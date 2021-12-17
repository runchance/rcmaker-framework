<?php
namespace RC\Helper;
use RC\Helper\Paginator;
use RC\Request;
use RC\Config;
use RC\Helper\Db\Medoo\Medoo;
use PDO;
class Db{
	private $request = null;
	private $connect = null;
	private $conn = null;
	private $engine = null;
	private $type = null;
	private $page = 1;
	private $order = null;
	private $group = null;
	private $having = null;
	private $join = null;
	private $where = null;
	private $whereExp = null;
	private $bind = null;
	private $limit = null;
	private $offset = null;
	private $field = null;
	private $lock = null;
	private $sql = null;
	private $enbleDebug = null;
	private $error = null;
	private $insertid = null;
	private $instance = null;
	private $medooJoin = null;
	private $medooWhere = null;
	private $medooOp = null;
	private static $_Query =null;
	private static $_Builder =null;
	private static $_DriverMap = [
		'mongodb'=>'mongo'
	];
	public function __construct($request,$engine=null,$type=null,$id=1){
		$this->engine = $engine ?? Config::get('db','default_frame');
		$this->type = $type ?? Config::get('db','default');
		$this->connect = \database($this->engine,$this->type,$id);
		$this->request = $request;
		
	}
	public function q($sql,$fetch=false){
		return $this->query($sql,$fetch);
	}
	public function query($sql,$fetch=false){
		switch($this->engine){
			case 'think':
				try {
					if($fetch){
						$result = $this->connect::query($sql);
					}else{
						$result = ($this->connect::execute($sql)===false) ? false : true;
					}
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->enbleDebug ? $sql : null;
				} 
			break;
			case 'laravel':
				try {
					if($fetch){
						$result = $this->connect::select($sql);
					}else{
						$result = $this->connect::unprepared($sql);
					}
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->enbleDebug ? $sql : null;
				} 
			break;
			case 'medoo':
				try {
					if($fetch){
						$result = $this->connect->query($sql)->fetchAll(PDO::FETCH_ASSOC);
					}else{
						$result = $this->connect->query($sql) ? true : false;
					}
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->enbleDebug ? $sql : null;
				}
			break;
		}
	}

	public function instance(){
		return $this->connect;
	}

	public function setConn($connect){
		$this->connect = $connect;
	}

	public function debug(){
		$this->enbleDebug = true;
		return $this;
	}

	public function conn(){
		return $this->conn;
	}

	public function native(){
		static $native;
		$native = $native ?? \database($this->engine,$this->type);
		return $native;
	}

	public function table($t=null){
		$this->table = $t;
		$this->removeOption(['join','where','whereExp','order','offset','limit','group','lock','error','medooJoin','medooWhere','medooOp']);
		return $this;
	}

	public function t($t=null){
		return $this->table($t);
	}

	public function sql(){
		return $this->sql;
	}

	public function id(){
		return $this->insertid;
	}


	public function error(){
		return $this->error;
	}

	public function e(){
		return $this->error();
	}

	public function bind($exp='join', ...$bind){
		$this->bind[] = [$exp,$bind];
		return $this;
	}

	public function b($exp='join', ...$bind){
		return $this->bind($exp,...$bind);
	}

	public function join(...$join){
		$this->join[] = $join;
		return $this;
	}
	public function j(...$join){
		return $this->join(...$join);
	}

	public function where(...$where){
		$this->where[] = count($where)>1 ? $where : ' '.$where[0];
		return $this;
	}
	public function w(...$where){
		return $this->where(...$where);
	}

	public function whereExp($exp, ...$where){
		$this->whereExp[] = [$exp, $where];
		return $this;
	}

	public function we($exp, ...$where){
		return $this->whereExp($exp, ...$where);
	}

	public function group($group){
		$this->group = $group;
		return $this;
	}

	public function g($group){
		return $this->group($group);
	}

	public function having($having){
		$this->having = $having;
		return $this;
	}

	public function h($having){
		return $this->having($having);
	}

	public function order($order){
		$this->order = $order;
		return $this;
	}

	public function o($order){
		return $this->order($order);
	}

	public function limit($limit=10,$offset=null){
		$this->limit = $limit;
		$this->offset = $offset;
		return $this;
	}

	public function l($limit=10,$offset=null){
		return $this->limit($limit,$offset);
	}

	private function removeOption(array $opts){
		foreach($opts as $opt){
			$this->{$opt} = null;
		}
	}

	private function build($builders = []){
		foreach($builders as $builder){
			if($builder=='table' && $this->table){
				switch($this->engine){
					case 'think':
						if(strpos(strtoupper($this->table),' AS ') !==false){
							$this->table = str_ireplace(' AS ',' ',$this->table);
						}
						$this->conn = $this->connect::table($this->table);
					break;
					case 'laravel':
						$this->conn = $this->connect::table($this->table);
					break;
					case 'medoo':
						if(strpos(strtoupper($this->table),' AS ') !==false){
							$tableExp = explode(' ',$this->table);
							$this->table = str_ireplace(' AS ','',$this->table);
							$this->table=$tableExp[0].'('.end($tableExp).')';
						}
					break;
				}
				
			}
			if($builder=='join' && $this->join){
				switch($this->engine){
					case 'think':
						foreach($this->join as $join){
							if(count($join)<3){
								return ;
							}
							$joinTable = $join[0];
							if(\strpos(\strtoupper($joinTable),' AS ') !==false){
								$join[0] = \str_ireplace(' AS ',' ',$join[0]);
							}
							$joinOn =  $join[1].' = '.$join[2];
							$join[1] = $joinOn;
							unset($join[2]);
							$this->conn->join(... $join);
						}
						
					break;
					case 'laravel':
						foreach($this->join as $join){
							$op = 'join';
							if(count($join)<3){
								return ;
							}
							$joinOp = end($join);
							switch(\strtoupper($joinOp)){
								case 'LEFT':
									$op = 'leftJoin';
								break;
								case 'RIGHT':
									$op = 'rightJoin';
								break;
							}
							$this->conn->{$op}(... $join);
						}
					break;
					case 'medoo':
						foreach($this->join as $join){
							$op = '[><]';
							if(count($join)<3){
								return ;
							}
							$joinOp = end($join);
							switch(\strtoupper($joinOp)){
								case 'LEFT':
									$op = '[>]';
								break;
								case 'RIGHT':
									$op = '[<]';
								break;
								case 'FULL':
									$op = '[<>]';
								break;
							}
							$joinTable = $op.$join[0];
							if(\strpos(\strtoupper($joinTable),' AS ') !==false){
								$tableExp = explode(' ',$joinTable);
								$joinTable = str_ireplace(' AS ','',$this->table);
								$joinTable = $tableExp[0].'('.end($tableExp).')';
							}
							$joinOn = explode('.',$join[2]);
							$this->medooJoin[$joinTable][$join[1]] = count($joinOn) > 1 ? $joinOn[1] : $join[2];
						}
					break;
				}
			}
			if($builder=='bind' && $this->bind){
				switch($this->engine){
					case 'think':case 'laravel':
						foreach($this->bind as $exps){
							$exp = $exps[0];
							$bind = $exps[1];
							$this->conn->{$exp}(... $bind);
						}
						
					break;
					case 'medoo':
						foreach($this->bind as $exps){
							$exp = $exps[0];
							$bind = $exps[1];
							$this->connect->{$exp}(... $bind);
						}
					break;
				}
			}
			if($builder=='where' && $this->where){
				switch($this->engine){
					case 'think':
						foreach($this->where as $where){
							if(is_array($where)){
								if(strtoupper(end($where))=='OR'){
									array_pop($where);
									$this->conn->whereOr(... $where);
								}else{
									$this->conn->where(... $where);
								}
							}else{
								$this->conn->whereRaw($where);
							}
						}
					break;
					case 'laravel':
						foreach($this->where as $where){
							if(is_array($where)){
								if(strtoupper(end($where))=='OR'){
									array_pop($where);
									$this->conn->orWhere(... $where);
								}else{
									$this->conn->where(... $where);
								}
							}else{
								$this->conn->whereRaw($where);	
							}
						}
					break;
					case 'medoo':
						foreach($this->where as $where){
							if(is_array($where)){
								$countWhere = count($where);
								if($countWhere<2){
									$this->medooWhere = null;
								}else{
									if($countWhere==2){
										$this->medooWhere[$where[0]] = $where[1];
									}
									if($countWhere>2){
										$op = $where[1];
										switch(strtolower($op)){
											case 'between': case 'bt': case 'wb':
												$op = '<>';
											break;
											case 'not between': case 'notbetween': case 'nbt': case 'wnb':
												$op = '><';
											break;
											case 'not in': case 'notin': case 'ni': case 'wni': case '<>':
												$op = '!';
											break;
											case 'like': case 'l': case 'wl':
												$op = '~';
											break;
											case 'not like': case 'notlike': case 'nl': case 'wnl':
												$op = '!~';
											break;
											case 'null': case 'isnull': case 'is null': case 'n': case 'wn':
												$where[2] = null;
											break;
											case 'notnull': case 'isnotnull': case 'not null': case 'is not null': case 'nn': case 'wnn':
												$op = '!';
												$where[2] = null;
											break;
											case '=':
												$op = null;
											break;
										}
										$joiner = $where[3] ?? 'AND';
										if(strtolower($joiner)=='or'){
											if($this->medooWhere){
												$medooWhere = $this->medooWhere;
												$this->medooWhere = null;
												if(isset($medooWhere['OR'])){
													$this->medooWhere['OR'] = $medooWhere['OR'];
													$this->medooWhere['OR'][$where[0].'['.$op.']'] = $where[2];
												}elseif(isset($medooWhere['AND'])){
													$this->medooWhere['AND'] = $medooWhere['AND'];
													$this->medooWhere['AND']['OR'][$where[0].'['.$op.']'] = $where[2];
												}else{
													$this->medooWhere['OR'][$where[0].'['.$op.']'] = $where[2];
													foreach($medooWhere as $key=>$mwhere){
														if(count($medooWhere)>1){
															$this->medooWhere['OR']['AND'][$key] = $mwhere;
														}else{
															$this->medooWhere['OR'][$key] = $mwhere;
														}
													}
												}
											}else{
												$this->medooWhere['OR'][$where[0].'['.$op.']'] = $where[2];
											}
											
										}else{
											if($this->medooWhere){
												$medooWhere = $this->medooWhere;
												$this->medooWhere = null;
												if(isset($medooWhere['AND']) || isset($medooWhere['OR'])){
													$this->medooWhere['AND'][$where[0].'['.$op.']'] = $where[2];
													if(isset($medooWhere['OR'])){
														$this->medooWhere['AND']['OR'] = $medooWhere['OR'];
													}
												}else{
													$this->medooWhere = $medooWhere;
													$this->medooWhere[$where[0].'['.$op.']'] = $where[2];
												}
											}else{
												$this->medooWhere[$where[0].'['.$op.']'] = $where[2]; 	
											}
										}
									}
								}
							}else{
								$this->medooWhere = Medoo::raw('WHERE '.$where);
							}
							
						}
					break;
				}
			}
			if($builder=='whereExp' && $this->whereExp){
				$expMap = [
					'or'=>'whereOr',
					'wb'=>'whereBetween',
					'wnb'=>'whereNotBetween',
					'wi'=>'whereIn',
					'wni'=>'whereNotIn',
					'wl'=>'whereLike',
					'wnl'=>'whereNotLike',
					'we'=>'whereExists',
					'wne'=>'whereNotExists',
					'wn'=>'whereNull',
					'wnn'=>'whereNotNull',
					'wt'=>'whereTime',
					'wbt'=>'whereBetweenTime',
					'wnbt'=>'whereNotBetweenTime',
					'wc'=>'whereColumn'
				];
				switch($this->engine){
					case 'think':
						foreach($this->whereExp as $exps){
							$exp = $exps[0];
							$where = $exps[1];
							if(isset($expMap[strtolower($exp)]) || $exp = array_search($exp, $expMap)){
								$this->conn->{$expMap[strtolower($exp)]}(... $where);
							}
						}
						
					break;
					case 'medoo':
						$expMap = [
							'wb'=>'whereBetween',
							'wnb'=>'whereNotBetween',
							'wi'=>'whereIn',
							'wni'=>'whereNotIn',
							'wl'=>'whereLike',
							'wnl'=>'whereNotLike',
							'wn'=>'whereNull',
							'wnn'=>'whereNotNull',
						];
						$this->where = null;
						foreach($this->whereExp as $exps){
							$exp = $exps[0];
							$where = $exps[1];
							$newWhere = [];
							if(isset($expMap[strtolower($exp)]) || $exp = array_search($exp, $expMap)){
								$newWhere[0] = $where[0];
								switch(strtolower($exp)){
									case 'wb':
										$newWhere[1] = '<>';
									break;
									case 'wnb':
										$newWhere[1] = '><';
									break;
									case 'wi':
										$newWhere[1] = '';
									break;
									case 'wni':
										$newWhere[1] = '!';
									break;
									case 'wl':
										$newWhere[1] = '~';
									break;
									case 'wnl':
										$newWhere[1] = '!~';
									break;
									case 'wn':
										$newWhere[2] = null;
									break;
									case 'wnn':
										$newWhere[1] = '!';
										$newWhere[2] = null;
									break;
								}
								if(isset($where[1])){
									$newWhere[2] = $newWhere[2] ?? $where[1];
								}
								if(isset($where[2])){
									$newWhere[3] = $where[2];
								}
								$this->where[] = $newWhere;
							}
						}
						if($this->where){
							$this->build(['where']);
						}
					break;
					case 'laravel':
						$expMap = [
							'or'=>'orWhere',
							'wb'=>'whereBetween',
							'orwb'=>'orWhereBetween',
							'wnb'=>'whereNotBetween',
							'orwnb'=>'orWhereNotBetween',
							'wc'=>'whereColumn',
							'orwc'=>'orWhereColumn',
							'wt'=>'whereTime',
							'orwt'=>'orWhereTime',
							'wd'=>'whereDay',
							'orwd'=>'orWhereDay',
							'wdt'=>'whereDate',
							'orwdt'=>'orWhereDate',
							'wm'=>'whereMonth',
							'orwm'=>'orWhereMonth',
							'wy'=>'whereYear',
							'orwy'=>'orWhereYear',
							'wi'=>'whereIn',
							'orwi'=>'orWhereIn', 
							'wni'=>'whereNotIn',
							'orwni'=>'orWhereNotIn',
							'wn'=>'whereNull',
							'orwn'=>'orWhereNull',
							'wnn'=>'whereNotNull',
							'orwnn'=>'orWhereNotNull',
						];
						foreach($this->whereExp as $exps){
							$exp = $exps[0];
							$where = $exps[1];
							if(isset($expMap[strtolower($exp)]) || $exp = array_search($exp, $expMap)){
								$end = end($where);
								if(substr($expMap[strtolower($exp)],0,2)!=='or' && (is_string($end) && strtolower($end)=='or')){
									$exp = 'or'.$exp;
									array_pop($where);
								}
								$this->conn->{$expMap[strtolower($exp)]}(... $where);
							}
						}
					break;
				}
			}
			if($builder=='order' && $this->order){
				$order = $this->order;
				switch($this->engine){
					case 'think':
						if(is_array($order)){
							$new = [];
							foreach($order as $k=>$v){
								if(isset($order[$k]['f'])){
									$new[$order[$k]['f']] = $order[$k]['t'] ?? 'DESC';
								}else{
									$new[$v[0]] = $v[1] ?? 'DESC';
								}
							}
							$this->conn->order($new);
						}else{
							if($order=='rand()'){
								$this->conn->orderRaw('rand()');
							}else{
								$this->conn->order($order);
							}
							
						}
						
						
					break;
					case 'laravel':
						if(is_array($order)){
							foreach($order as $k=>$v){
								if(isset($order[$k]['f'])){
									$this->conn->orderBy($order[$k]['f'],$order[$k]['t'] ?? 'DESC');
								}else{
									$this->conn->orderBy($v[0],$v[1] ?? 'DESC');
								}
							}
						}else{
							if($order=='rand()'){
								$this->conn->inRandomOrder();
							}else{
								$this->conn->orderBy($order, 'ASC');	
							}
						}
					break;
					case 'medoo':
						if(is_array($order)){
							foreach($order as $k=>$v){
								if(isset($order[$k]['f'])){
									$this->medooWhere['ORDER'][$order[$k]['f']] = $order[$k]['t'] ?? 'DESC';
								}else{
									$this->medooWhere['ORDER'][$v[0]] = $v[1] ?? 'DESC';
								}
							}
						}else{
							if($order=='rand()'){
								$this->medooOp='rand';
							}else{
								$this->medooWhere['ORDER'][$order] = 'ASC';
							}
							
						}
					break;
				}
				
			}
			if($builder=='limit' && $this->limit){
				switch($this->engine){
					case 'think':
						if($this->offset){
							$this->conn->limit($this->offset,$this->limit);
						}else{
							$this->conn->limit($this->limit);
						}

					break;
					case 'laravel':
						if($this->offset){
							$this->conn->offset($this->offset)->limit($this->limit);
						}else{
							$this->conn->limit($this->limit);
						}

					break;
					case 'medoo':
						if($this->offset){
							$this->medooWhere['LIMIT'] = [$this->offset,$this->limit];
						}else{
							$this->medooWhere['LIMIT'] = $this->limit;
						}

					break;
				}
				
			}
			if($builder=='group' && $this->group){
				switch($this->engine){
					case 'think':
						$this->conn->group($this->group);
					break;
					case 'laravel':
						$this->conn->groupBy($this->group);
					break;
					case 'medoo':
						$this->medooWhere['GROUP'] = $this->group;
					break;
				}
				
			}
			if($builder=='having' && $this->having){
				$having = $this->having;
				switch($this->engine){
					case 'think':
						$this->conn->having($having);
					break;
					case 'laravel':
						$this->conn->havingRaw($having);
					break;
					case 'medoo':
						
			            if(!preg_match(
			                '/(?<key>([\p{L}_][\p{L}\p{N}@$#\-_\.\(\)]*))(?<operator>\>\=?|\<\=?|\=|\!\=?|\<\>|\>\<|\!?~|REGEXP)(?<val>(.*))/i',
			                $having,
			                $match
			            )){
			            	return ;
			            }

			            $key = $match['key'] ?? null;
			            $op = $match['operator'] ?? null;
			            $val = $match['val'] ?? null;
			            if($key===null || $op===null || $val===null){
			            	return ;
			            }
			            if (!in_array($op, ['>', '>=', '<', '<='])) {
			            	return ;
			            }
			            $havingFactor = $key.($op!=='=' ? '['.$op.']' : '');
						$this->medooWhere['HAVING'][$havingFactor] = $val;
					break;
				}
			}
			if($builder=='lock' && $this->lock){
				
				switch($this->engine){
					case 'think':
						$this->conn->lock($this->lock);
					break;
					case 'laravel':
						if((is_bool($this->lock) && $this->lock) || (is_string($this->lock) && \strtoupper($this->lock)==='FOR UPDATE')){
							$this->conn->lockForUpdate();
						}else{
							if (is_string($this->lock) && !empty($this->lock)){
								$this->conn->sharedLock();
							}
						}
					break;
					case 'medoo':
						$this->medooWhere['LOCK'] = $this->lock;
					break;
				}
				
			}
		}
	}

	public function cm(){
		return $this->commit();
	}

	public function commit(){
		switch($this->engine){
			case 'think':
				return $this->conn->commit();
			break;
			case 'laravel':
				return $this->connect::commit();
			break;
			case 'medoo':
				$this->connect->pdo->commit();
			break;
		}
	}

	public function rb(){
		return $this->rollback();
	}

	public function rollback(){
		switch($this->engine){
			case 'think':
				return $this->conn->rollback();
			break;
			case 'laravel':
				return $this->connect::rollback();
			break;
			case 'medoo':
				$this->connect->pdo->rollBack();
			break;
		}
	}

	public function lc($lock){ //锁表
		return $this->lock($lock);
	}
	public function lock($lock){ //锁表
		$this->lock = $lock;
		return $this;
	}

	public function st(){
		return $this->startTrans();
	}
	public function startTrans(){
		switch($this->engine){
			case 'think':
				$this->connect::startTrans();
			break;
			case 'laravel':
				$this->connect::beginTransaction();
			break;
			case 'medoo':
				$this->connect->pdo->beginTransaction();
			break;

		}
	}

	public function d(){
		return $this->delete();
	}

	public function delete(){
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		switch($this->engine){
			case 'think':
				try {
					$result = $this->conn->delete();
					return true;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				} finally{
					$this->sql = $this->enbleDebug ? $this->connect::table($this->table)->getLastSql() : null;
				} 
			break;
			case 'laravel':
				try {
					if($this->enbleDebug){
						$this->connect::enableQueryLog();
					}
					$result = $this->conn->delete();
					return true;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				} finally{
					if($this->enbleDebug){
						$log = $this->connect::getQueryLog();
						$log = end($log);
						$this->sql = $log ? vsprintf(\str_replace(['?'], ['\'%s\''],$log['query']), $log['bindings']) : null;
					}
				} 
			break;
			case 'medoo':
				try {
					$result = $this->connect->delete($this->table,$this->medooWhere ?? []);
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->enbleDebug ? $this->connect->last() : null;
				}
			break;
		}
	}

	public function f($field='*'){
		return $this->find($field);
	}
	public function find($field='*'){
		$this->field = $field;
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		switch($this->engine){
			case 'think':
				try {
					if($this->where===null && $this->whereExp===null){
				       $this->conn->where('1=1');
				    }
					$result = $this->conn->field($field)->find();
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->enbleDebug ? $this->connect::table($this->table)->getLastSql() : null;
				}
			break;
			case 'laravel':
				try {
					if($this->enbleDebug){
						$this->connect::enableQueryLog();
					}
					$result = $this->conn->select(... explode(',',$field))->first();
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if($this->enbleDebug){
						$log = $this->connect::getQueryLog();
						$log = end($log);
						$this->sql = $log ? vsprintf(\str_replace(['?'], ['\'%s\''],$log['query']), $log['bindings']) : null;
					}
				}
			break;
			case 'medoo':
				if(strpos($field,',')!==false){
					$field = explode(',',$field);
				}else{
					if($field!=='*'){
						$field = [$field];
					}
				}
				try {
					if($this->medooOp=='rand'){
						if(isset($this->medooWhere['LIMIT'])){
							if(is_array($this->medooWhere['LIMIT'])){
								$this->medooWhere['LIMIT'][1] = 1;
							}
						}else{
							$this->medooWhere['LIMIT'] = 1;
						}
					}

					if($this->medooJoin){
						$result = $this->medooOp ? $this->connect->{$this->medooOp}($this->table,$this->medooJoin ?? null,$field,$this->medooWhere ?? []) : $this->connect->get($this->table,$this->medooJoin ?? null,$field,$this->medooWhere ?? []);
					}else{
						$result = $this->medooOp ? $this->connect->{$this->medooOp}($this->table,$field,$this->medooWhere ?? []) : $this->connect->get($this->table,$field,$this->medooWhere ?? []);
					}
					return $this->medooOp=='rand' ? ($result[0] ?? null) : $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->enbleDebug ? $this->connect->last() : null;
				}
			break;
		}
	}

	public function a(array $data){
		return $this->add($data);
	}
	public function add(array $data){
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		switch($this->engine){
			case 'think':
				try {
					$result = $this->conn->insert($data);
					$this->insertid = $this->connect::table($this->table)->getLastInsID();
					return true;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				} finally{
					$this->sql = $this->enbleDebug ? $this->connect::table($this->table)->getLastSql() : null;
				} 
			break;
			case 'laravel':
				try {
					if($this->enbleDebug){
						$this->connect::enableQueryLog();
					}
					$result = $this->insertid = $this->conn->insertGetId($data);
					return true;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				} finally{
					if($this->enbleDebug){
						$log = $this->connect::getQueryLog();
						$log = end($log);
						$this->sql = $log ? vsprintf(\str_replace(['?'], ['\'%s\''],$log['query']), $log['bindings']) : null;
					}
				} 
			break;
			case 'medoo':
				try {
					$result = $this->connect->insert($this->table,$data);
					$this->insertid = $this->connect->id();
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->enbleDebug ? $this->connect->last() : null;
				}
			break;
		}
	}

	public function u(array $data){
		return $this->update($data);
	}
	public function update(array $data){
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		switch($this->engine){
			case 'think':
				try {
					$result = $this->conn->update($data);
					return true;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->enbleDebug ? $this->connect::table($this->table)->getLastSql() : null;
				} 
			break;
			case 'laravel':
				try {
					if($this->enbleDebug){
						$this->connect::enableQueryLog();
					}
					$result = $this->conn->update($data);
					return true;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if($this->enbleDebug){
						$log = $this->connect::getQueryLog();
						$log = end($log);
						$this->sql = $log ? vsprintf(\str_replace(['?'], ['\'%s\''],$log['query']), $log['bindings']) : null;
					}
				} 
			break;
			case 'medoo':
				try {
					$result = $this->connect->update($this->table,$data,$this->medooWhere ?? []);
					$this->insertid = $this->connect->id();
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->enbleDebug ? $this->connect->last() : null;
				}
			break;
		}
	}

	public function p($field='*',$listRows = null,$simple = false){
		return $this->paginate($field,$listRows,$simple);
	}
	public function paginate($field='*',$listRows = null,$simple = false){
		$this->field = $field;
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		switch($this->engine){
			case 'think':
				$this->conn->field($field);

				if (is_int($simple)) {
		            $total  = $simple;
		            $simple = false;
		        }
		        $defaultConfig = [
		            'query'     => [], //url额外参数
		            'fragment'  => '', //url锚点
		            'var_page'  => 'page', //分页变量
		            'list_rows' => 15, //每页数量
		        ];

		        if (is_array($listRows)) {
		            $config   = array_merge($defaultConfig, $listRows);
		            $listRows = intval($config['list_rows']);
		        } else {
		            $config   = $defaultConfig;
		            $listRows = intval($listRows ?: $config['list_rows']);
		            $config['list_rows'] = $listRows;
		        }
				$page = isset($config['page']) ? (int) $config['page'] : (int) $this->request->get($config['var_page']);
		        $page = $page < 1 ? 1 : $page;
		        $config['page'] = $page;
				try {
					$result = $this->conn->paginate($config,$simple);
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->enbleDebug ? $this->conn->getLastSql() : null;
				} 
				return $result;
			break;
			case 'laravel':

				if (is_int($simple)) {
		            $total  = $simple;
		            $simple = false;
		        }
				$defaultConfig = [
					'perPage' => 15, //每页数量
					'columns' => explode(',',$field),
		            'pageName'  => 'page' //分页变量
		        ];

		        $configMap = [
		        	'list_rows'=>'perPage',
		        	'var_page'=>'pageName',
		        ];
		        $query = [];
		        $fragment = ''; //url锚点
		        $path = '/?page=[PAGE]';
		        if (is_array($listRows)) {
		        	$query = $listRows['query'] ?? [];
		        	$fragment = $listRows['fragment'] ?? '';
		        	if(isset($listRows['path'])){
		        		$path = $listRows['path'];
		        		unset($listRows['path']);
		        	}
		        	$newConfig = [];
		        	foreach($listRows as $key=>$val){
		        		if(isset($configMap[$key])){
		        			$newConfig[$configMap[$key]] = $val;
		        		}
		        	}
		            $config   = array_merge($defaultConfig, $newConfig);
		            $listRows = intval($config['perPage']);
		        } else {
		        	$listRows = intval($listRows ?: $config['perPage']);
		            $config   = $defaultConfig;
		            $config['perPage'] = $listRows;

		        }
		        $page = isset($config['page']) ? (int) $config['page'] : (int) $this->request->get($config['pageName']);
		        $page = $page < 1 ? 1 : $page;
		        $config['page'] = $page;
				try {
					if($this->enbleDebug){
						$this->connect::enableQueryLog();
					}
					if (!isset($total) && !$simple) {

						$paginate = $this->conn->paginate(... array_values($config));
						if($query){
							$result->appends($query);
						}
						if(!empty($fragment)){
							$result->fragment($fragment);
						}
						$total = $paginate->total();
						$result = $paginate->items();
					}elseif ($simple) {

						$paginate = $this->conn->simplePaginate(... array_values($config));
						$total = null;
						$result = $paginate->items();
					}else{
						$paginate = $this->conn->simplePaginate(... array_values($config));
						$result = $paginate->items();
					}
				
					return new Paginator($total, $listRows, $page, $path, $result);
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					if($this->enbleDebug){
						$log = $this->connect::getQueryLog();
						$log = end($log);
						$this->sql = $log ? vsprintf(\str_replace(['?'], ['\'%s\''],$log['query']), $log['bindings']) : null;
					}
				} 
				return $result;
			break;
			case 'medoo':
				if(strpos($field,',')!==false){
					$field = explode(',',$field);
				}else{
					if($field!=='*'){
						$field = [$field];
					}
				}
				$totalWhere = $this->medooWhere;
				unset($totalWhere['LIMIT']);
				unset($totalWhere['ORDER']);
				if (is_int($simple)) {
		            $total  = $simple;
		            $simple = false;
		        }
		        $defaultConfig = [
		            'query'     => [], //url额外参数
		            'fragment'  => '', //url锚点
		            'var_page'  => 'page', //分页变量
		            'list_rows' => 15, //每页数量
		        ];
		        $path = '/?page=[PAGE]';
		        if (is_array($listRows)) {
		        	if(isset($listRows['path'])){
		        		$path = $listRows['path'];
		        		unset($listRows['path']);
		        	}
		            $config   = array_merge($defaultConfig, $listRows);
		            $listRows = intval($config['list_rows']);
		        } else {
		            $config   = $defaultConfig;
		            $listRows = intval($listRows ?: $config['list_rows']);
		        }

		        $page = isset($config['page']) ? (int) $config['page'] : (int) $this->request->get($config['var_page']);
		        $page = $page < 1 ? 1 : $page;
		        try {
			        if (!isset($total) && !$simple) {
			        	if($this->medooJoin){
			        		if($this->group){
			        			\ob_start();
								if($this->medooJoin){
			        			 	$this->connect->debug()->count($this->table,$this->medooJoin ?? null,$field,$this->medooWhere ?? []);
			        			 }else{
			        			 	$this->connect->debug()->count($this->table,$field,$this->medooWhere ?? []);
			        			 }
								$subsql = \ob_get_clean();
			        			$total = $this->connect->count('<custom>('.$subsql.') AS count','*',[]);
			        		}else{
			        			$total = (int) $this->connect->count($this->table,$this->medooJoin ?? null,$field,$totalWhere ?? []);
			        		}
				        	$this->medooWhere['LIMIT'] = [($page - 1) * $listRows,$listRows];
							$result = $this->connect->select($this->table,$this->medooJoin ?? null,$field,$this->medooWhere ?? []);
						}else{
							if($this->group){
								\ob_start();
								 $this->connect->debug()->count($this->table,$field,$totalWhere ?? []);
								$subsql = \ob_get_clean();
			        			$total = $this->connect->count('<custom>('.$subsql.') AS count','*',[]);
			        		}else{
			        			$total = (int) $this->connect->count($this->table,$field,$totalWhere ?? []);
			        		}
							$this->medooWhere['LIMIT'] = [($page - 1) * $listRows,$listRows];
							$result = $this->connect->select($this->table,$field,$this->medooWhere ?? []);
						}
			        } elseif ($simple) {
			  			if($this->medooJoin){
				        	$this->medooWhere['LIMIT'] = [($page - 1) * $listRows,$listRows];
							$result = $this->connect->select($this->table,$this->medooJoin ?? null,$field,$this->medooWhere ?? []);
						}else{
							$this->medooWhere['LIMIT'] = [($page - 1) * $listRows,$listRows];
							$result = $this->connect->select($this->table,$field,$this->medooWhere ?? []);
						}
			            $total   = null;
			        } else {
			            if($this->medooJoin){
				        	$this->medooWhere['LIMIT'] = [($page - 1) * $listRows,$listRows];
							$result = $this->connect->select($this->table,$this->medooJoin ?? null,$field,$this->medooWhere ?? []);
						}else{
							$this->medooWhere['LIMIT'] = [($page - 1) * $listRows,$listRows];
							$result = $this->connect->select($this->table,$field,$this->medooWhere ?? []);
						}
			        }
			        return new Paginator($total, $listRows, $page, $path, $result);
			    }catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->enbleDebug ? $this->connect->last() : null;
				} 
			break;
		}
	}

	public function s($field='*'){
		return $this->select($field);
	}
	public function select($field='*'){
		$this->field = $field;
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		switch($this->engine){
			case 'think':
				try {
					$result = $this->conn->field($field)->select();
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->conn->getLastSql();
				} 
			break;
			case 'laravel':
				try {
					if($this->enbleDebug){
						$this->connect::enableQueryLog();
					}
					$result = $this->conn->select(... explode(',',$field))->get();
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$log = $this->connect::getQueryLog();
					$log = end($log);
					$this->sql = $log ? vsprintf(\str_replace(['?'], ['\'%s\''],$log['query']), $log['bindings']) : null;
				} 
			break;
			case 'medoo':
				if(strpos($field,',')!==false){
					$field = explode(',',$field);
				}else{
					if($field!=='*'){
						$field = [$field];
					}
				}
				try {
					if($this->medooJoin){
						$result = $this->medooOp ? $this->connect->{$this->medooOp}($this->table,$this->medooJoin ?? null,$field,$this->medooWhere ?? []) : $this->connect->select($this->table,$this->medooJoin ?? null,$field,$this->medooWhere ?? []);
					}else{
						$result = $this->medooOp ? $this->connect->{$this->medooOp}($this->table,$field,$this->medooWhere ?? []) : $this->connect->select($this->table,$field,$this->medooWhere ?? []);
					}
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->enbleDebug ? $this->connect->last() : null;
				}
			break;
		}
	}

	public function c($field='*'){
		return $this->count($field);
	}
	public function count($field='*'){
		$this->field = $field;
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		switch($this->engine){
			case 'think':
				try {
					$result = $this->conn->field($field)->count();
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->conn->getLastSql();
				} 
			break;
			case 'laravel':
				try {
					if($this->enbleDebug){
						$this->connect::enableQueryLog();
					}
					if($this->group){
						$result = $this->conn->select(... explode(',',$field))->get()->count();
					}else{
						$result = $this->conn->select(... explode(',',$field))->count();
					}
					
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$log = $this->connect::getQueryLog();
					$log = end($log);
					$this->sql = $log ? vsprintf(\str_replace(['?'], ['\'%s\''],$log['query']), $log['bindings']) : null;
				} 
			break;
			case 'medoo':
				if(strpos($field,',')!==false){
					$field = explode(',',$field);
				}else{
					if($field!=='*'){
						$field = [$field];
					}
				}
				try {
					if($this->group){
	        			\ob_start();
	        			 if($this->medooJoin){
	        			 	$this->connect->debug()->count($this->table,$this->medooJoin ?? null,$field,$this->medooWhere ?? []);
	        			 }else{
	        			 	$this->connect->debug()->count($this->table,$field,$this->medooWhere ?? []);
	        			 }
						$subsql = \ob_get_clean();
	        			$result = $this->connect->count('<custom>('.$subsql.') AS count','*',[]);
	        		}else{
	        			if($this->medooJoin){
							$result = $this->medooOp ? $this->connect->{$this->medooOp}($this->table,$this->medooJoin ?? null,$field,$this->medooWhere ?? []) : $this->connect->count($this->table,$this->medooJoin ?? null,$field,$this->medooWhere ?? []);
						}else{
							$result = $this->medooOp ? $this->connect->{$this->medooOp}($this->table,$field,$this->medooWhere ?? []) : $this->connect->count($this->table,$field,$this->medooWhere ?? []);
						}
	        		}
					
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->enbleDebug ? $this->connect->last() : null;
				}
			break;
		}
	}



	public function agg($fun,$field){
		$fun = $fun ? strtolower($fun) : null;
		if(!$field || !in_array(strtolower($fun),['max','min','avg','sum'])){
			return null;
		}

		$this->field = $field;
		$this->build(['table','bind','join','where','whereExp','order','limit','group','having','lock']);
		switch($this->engine){
			case 'think':
				try {
					$result = $this->conn->{$fun}($field);
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->conn->getLastSql();
				} 
			break;
			case 'laravel':
				try {
					$this->connect::enableQueryLog();
					$result = $this->conn->{$fun}($field);
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$log = $this->connect::getQueryLog();
					$log = end($log);
					$this->sql = $log ? vsprintf(\str_replace(['?'], ['\'%s\''],$log['query']), $log['bindings']) : null;
				} 
			break;
			case 'medoo':
				try {
					if($this->medooJoin){
						$result = $this->medooOp ? $this->connect->{$this->medooOp}($this->table,$this->medooJoin ?? null,$field,$this->medooWhere ?? []) : $this->connect->{$fun}($this->table,$this->medooJoin ?? null,$field,$this->medooWhere ?? []);
					}else{
						$result = $this->medooOp ? $this->connect->{$this->medooOp}($this->table,$field,$this->medooWhere ?? []) : $this->connect->{$fun}($this->table,$field,$this->medooWhere ?? []);
					}
					return $result;
				}catch(\Throwable $ex){
					$this->error = $ex->getMessage();
					return false;
				}finally{
					$this->sql = $this->enbleDebug ? $this->connect->last() : null;
				}
			break;
		}
	}
}
?>