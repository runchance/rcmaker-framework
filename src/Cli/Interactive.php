<?php

declare(strict_types=1);

namespace RC\Cli;

use Phar;
use RuntimeException;
use Throwable;

final class InteractiveInputClosed extends RuntimeException
{
}

final class Interactive
{
    private const RESET = "\033[0m";
    private const BOLD = "\033[1m";
    private const DIM = "\033[2m";
    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const CYAN = "\033[36m";

    private static bool $color = false;

    public static function run(): int
    {
        if (!in_array(PHP_SAPI, ['cli', 'micro'], true)) {
            fwrite(STDERR, "Interactive mode is available only in CLI mode.\n");
            return 1;
        }
        if (class_exists(Phar::class, false) && Phar::running(false) !== '') {
            fwrite(STDERR, "Interactive tools require a source project and cannot run inside a packaged executable.\n");
            return 1;
        }
        if (filter_var(ini_get('phar.readonly'), FILTER_VALIDATE_BOOL)) {
            return self::relaunchWithWritablePhar();
        }

        self::$color = self::supportsColor();
        self::header();
        try {
            while (true) {
                self::section('脚本工具');
                self::option('1', '构建二进制可执行文件');
                self::option('2', '加密 PHP 文件或目录');
                self::option('3', '注册或移除 Linux systemd 服务');
                self::option('4', '生成证书 / Token 签名密钥');
                self::option('5', '安装或更新 rcmaker AI DevKit');
                self::option('6', '退出');
                $choice = strtolower(self::ask('请选择功能', '6'));

                if (in_array($choice, ['6', '0', 'q', 'quit', 'exit'], true)) {
                    self::success('已退出。');
                    return 0;
                }
                try {
                    match ($choice) {
                        '1' => self::buildBinary(),
                        '2' => self::encryptPhp(),
                        '3' => self::manageSystemd(),
                        '4' => self::generateTokenKey(),
                        '5' => self::manageAiDevkit(),
                        default => self::warning('请输入 1 - 6。'),
                    };
                } catch (InteractiveInputClosed $closed) {
                    throw $closed;
                } catch (Throwable $throwable) {
                    self::error($throwable->getMessage());
                }
            }
        } catch (InteractiveInputClosed) {
            self::line();
            self::success('输入已结束。');
            return 0;
        }
    }

    private static function buildBinary(): void
    {
        self::section('构建二进制可执行文件');
        $platform = self::platform();
        $arch = self::arch();
        $version = self::phpVersion();
        $exclude = self::ask('额外排除文件或目录，多个路径用逗号分隔', '');
        $encrypt = self::confirm('是否加密项目源码', false);
        $customIni = self::ask('自定义 php.ini 内容或 ini 文件路径', '');
        $options = [
            'platform' => $platform,
            'arch' => $arch,
            'with-php' => $version,
            'exclude-files' => $exclude,
            'encrypt' => $encrypt,
            'custom-ini' => $customIni,
        ];

        self::summary([
            '操作系统' => $platform,
            '架构' => $arch,
            'PHP 版本' => $version,
            '额外排除路径' => $exclude !== '' ? $exclude : '无',
            '源码加密' => $encrypt ? '是' : '否',
            '自定义 INI' => $customIni !== '' ? $customIni : '无',
            '执行引擎' => BuildBinary::class,
        ]);
        if (!self::confirm('确认开始构建', true)) {
            self::warning('已取消构建。');
            return;
        }

        $result = BuildBinary::execute(self::rootPath(), $options, self::outputCallback());
        self::success('构建完成：' . $result['path']);
    }

    private static function encryptPhp(): void
    {
        self::section('加密 PHP 文件或目录');
        $input = self::askExistingPath('输入文件或目录');
        $isDirectory = is_dir(Filesystem::normalizedAbsolute($input, self::rootPath()));
        $output = self::askRequired('输出文件或目录', self::defaultEncryptedOutput($input));
        $exclude = $isDirectory ? self::ask('排除文件或目录，多个路径用逗号分隔', '') : '';
        $force = self::confirm('目标存在时是否覆盖', false);
        $downloadRuntime = self::confirm('是否同时下载独立 PHP 运行时', false);
        $buildBin = self::confirm('是否同时生成单文件可执行程序', false);
        $needsTarget = $downloadRuntime || $buildBin;
        $platform = 'auto';
        $arch = 'auto';
        $version = '8.4';
        if ($needsTarget) {
            $platform = self::platform();
            $arch = self::arch();
            $version = self::phpVersion();
        }
        $runtimeOutput = $downloadRuntime
            ? self::ask('独立 PHP 输出路径，留空则写在加密结果旁', '')
            : '';
        $buildBinPath = '';
        $entry = '';
        $customIni = '';
        if ($buildBin) {
            $buildBinPath = self::askRequired(
                '可执行程序输出路径',
                './build/' . (self::targetIsWindows($platform) ? 'app.exe' : 'app.bin')
            );
            if ($isDirectory) {
                $entry = self::ask('Phar 入口文件（相对加密输出目录）', 'index.php');
            }
            $customIni = self::ask('自定义 php.ini 内容或 ini 文件路径', '');
        }

        $options = [
            'input' => $input,
            'output' => $output,
            'platform' => $platform,
            'arch' => $arch,
            'with-php' => $version,
            'exclude-files' => $exclude,
            'force' => $force,
            'download-runtime' => $downloadRuntime,
            'runtime-output' => $runtimeOutput,
            'build-bin' => $buildBinPath,
            'entry' => $entry,
            'custom-ini' => $customIni,
        ];

        $summary = [
            '输入路径' => $input,
            '输出路径' => $output,
            '排除路径' => $exclude !== '' ? $exclude : '无',
            '覆盖目标' => $force ? '是' : '否',
        ];
        if ($needsTarget) {
            $summary += [
                '目标操作系统' => $platform,
                '目标架构' => $arch,
                '目标 PHP 版本' => $version,
            ];
        }
        $summary += [
            '下载运行时' => $downloadRuntime ? '是' : '否',
            '生成可执行程序' => $buildBinPath !== '' ? $buildBinPath : '否',
            '执行引擎' => EncryptPhp::class,
        ];
        self::summary($summary);
        if (!self::confirm('确认开始加密', true)) {
            self::warning('已取消加密。');
            return;
        }

        $result = EncryptPhp::execute(self::rootPath(), $options, self::outputCallback());
        self::success('加密完成：' . $result['output']);
    }

    private static function manageSystemd(): void
    {
        self::section('Linux systemd 服务');
        if (PHP_OS_FAMILY !== 'Linux') {
            self::warning('该功能仅支持 Linux，当前平台为 ' . PHP_OS_FAMILY . '。');
            return;
        }
        if (!function_exists('posix_getuid') || posix_getuid() !== 0) {
            self::warning('该功能需要 root 权限。');
            self::line('请重新执行：sudo ' . ProcessRunner::display([PHP_BINARY, self::entryFile(), 'interact']));
            return;
        }

        $operation = self::select('操作', [
            '1' => ['add', '注册服务'],
            '2' => ['remove', '移除服务'],
        ], '1');
        $name = self::askMatching(
            '服务名称',
            'rcmaker',
            '/^[a-z][a-z0-9_-]{0,19}$/',
            '服务名必须以小写字母开头，最长 20 个字符。'
        );
        $mode = $operation === 'add'
            ? self::select('启动方式', [
                '1' => ['php', '使用 PHP 运行 index.php'],
                '2' => ['bin', '使用 build/rcmaker.bin'],
            ], '1')
            : 'php';
        $user = self::askMatching(
            '服务运行用户',
            'root',
            '/^[a-z_][a-z0-9_-]{0,31}$/',
            '用户名称格式不正确。'
        );
        $phpBinary = $mode === 'php' && $operation === 'add'
            ? self::askRequired('PHP 可执行文件绝对路径', PHP_BINARY)
            : PHP_BINARY;
        $options = [
            'operation' => $operation,
            'name' => $name,
            'mode' => $mode,
            'user' => $user,
            'php' => $phpBinary,
        ];

        self::summary([
            '操作' => $operation === 'add' ? '注册' : '移除',
            '服务名称' => $name,
            '启动方式' => $mode === 'bin' ? '独立可执行程序' : 'PHP',
            '运行用户' => $user,
            'PHP 路径' => $mode === 'php' ? $phpBinary : '不使用',
            '执行引擎' => SystemdService::class,
        ]);
        if (!self::confirm('该操作会修改系统服务，确认继续', false)) {
            self::warning('已取消 systemd 操作。');
            return;
        }

        $result = SystemdService::execute(self::rootPath(), $options, self::outputCallback());
        self::success('systemd 操作完成：' . $result['name']);
    }

    private static function generateTokenKey(): void
    {
        self::section('证书 / Token 签名密钥');
        $algorithm = self::select('签名算法', [
            '1' => ['RS256', 'RSA SHA-256（推荐）'],
            '2' => ['RS384', 'RSA SHA-384'],
            '3' => ['RS512', 'RSA SHA-512'],
            '4' => ['ES256', 'ECDSA P-256'],
            '5' => ['ES384', 'ECDSA P-384'],
            '6' => ['EDDSA', 'Ed25519'],
        ], '1');
        $config = $algorithm === 'EDDSA' ? '' : self::ask('OpenSSL 配置文件路径，通常留空', '');
        self::summary([
            '签名算法' => $algorithm,
            'OpenSSL 配置' => $config !== '' ? $config : '系统默认',
            '输出目录' => self::rootPath() . DIRECTORY_SEPARATOR . 'ssl',
            '执行引擎' => TokenKey::class,
        ]);
        if (!self::confirm('确认生成密钥', true)) {
            self::warning('已取消密钥生成。');
            return;
        }

        $result = TokenKey::execute(self::rootPath(), [
            'algorithm' => $algorithm,
            'openssl-config' => $config,
        ], self::outputCallback());
        self::success('密钥生成完成：' . $result['algorithm']);
    }

    private static function manageAiDevkit(): void
    {
        self::section('rcmaker AI DevKit');
        $rootPath = self::rootPath();
        $installedVersion = AiDevkit::installedVersion($rootPath);
        $offlineArchive = AiDevkit::offlineArchivePath($rootPath);
        $hasOfflineArchive = is_file($offlineArchive);

        self::summary([
            '当前版本' => $installedVersion ?? (AiDevkit::hasManifest($rootPath) ? '无法识别' : '未安装'),
            '安装来源' => $hasOfflineArchive ? '项目根目录离线包' : 'GitHub 最新 Release',
            '离线包路径' => $offlineArchive,
        ]);
        self::line('同名文件更新前会备份到 data/rcmaker-ai-devkit-backup/。', self::DIM);
        if (!self::confirm($installedVersion === null ? '确认安装 AI DevKit' : '确认检查并更新 AI DevKit', true)) {
            self::warning('已取消 AI DevKit 操作。');
            return;
        }

        try {
            $result = AiDevkit::install($rootPath, self::outputCallback());
        } catch (AiDevkitDownloadException $exception) {
            self::warning('无法从 GitHub 下载 rcmaker AI DevKit。');
            self::line('原因：' . $exception->getMessage());
            self::line('请离线下载：' . AiDevkit::DOWNLOAD_URL);
            self::line('将文件保存为：' . $offlineArchive);
            self::line('然后重新运行 php index.php interact，选择本功能即可安装。');
            return;
        }

        self::success('rcmaker AI DevKit 已安装：v' . $result['version']);
        self::line(
            '  新增 ' . $result['installed'] . ' 个，更新 ' . $result['updated']
            . ' 个，未变化 ' . $result['unchanged'] . ' 个。'
        );
        if ($result['backup'] !== null) {
            self::info('原文件备份：' . $result['backup']);
        }
        if ($result['source'] === 'offline') {
            self::info('离线包已保留，可确认安装结果后手动删除：' . $offlineArchive);
        }
    }

    private static function relaunchWithWritablePhar(): int
    {
        if (PHP_BINARY === '') {
            fwrite(STDERR, "Unable to resolve PHP_BINARY for interactive mode.\n");
            return 1;
        }
        return ProcessRunner::run(
            [PHP_BINARY, '-d', 'phar.readonly=0', self::entryFile(), 'interact'],
            self::rootPath(),
            true
        );
    }

    private static function platform(): string
    {
        return self::select('目标操作系统', [
            '1' => ['auto', '自动检测'],
            '2' => ['linux', 'Linux'],
            '3' => ['macos', 'macOS'],
            '4' => ['windows', 'Windows'],
        ], '1');
    }

    private static function arch(): string
    {
        return self::select('目标架构', [
            '1' => ['auto', '自动检测'],
            '2' => ['x86_64', 'x86_64 / AMD64'],
            '3' => ['aarch64', 'AArch64 / ARM64'],
        ], '1');
    }

    private static function phpVersion(): string
    {
        return self::select('PHP 版本', [
            '1' => ['8.1', 'PHP 8.1'],
            '2' => ['8.2', 'PHP 8.2'],
            '3' => ['8.3', 'PHP 8.3'],
            '4' => ['8.4', 'PHP 8.4'],
            '5' => ['8.5', 'PHP 8.5'],
        ], '4');
    }

    private static function select(string $label, array $options, string $default): string
    {
        self::line();
        self::line($label, self::BOLD);
        foreach ($options as $key => [$value, $description]) {
            self::option((string)$key, $description . ' [' . $value . ']');
        }
        while (true) {
            $input = strtolower(self::ask('请输入编号', $default));
            if (isset($options[$input])) {
                return $options[$input][0];
            }
            foreach ($options as [$value]) {
                if (strtolower($value) === $input) {
                    return $value;
                }
            }
            self::warning('选项无效，请重新输入。');
        }
    }

    private static function askExistingPath(string $label): string
    {
        while (true) {
            $path = self::askRequired($label, '');
            if (file_exists(Filesystem::normalizedAbsolute($path, self::rootPath()))) {
                return $path;
            }
            self::warning('路径不存在：' . Filesystem::normalizedAbsolute($path, self::rootPath()));
        }
    }

    private static function askRequired(string $label, string $default): string
    {
        while (true) {
            $value = self::ask($label, $default);
            if ($value !== '') {
                return $value;
            }
            self::warning('该项不能为空。');
        }
    }

    private static function askMatching(string $label, string $default, string $pattern, string $error): string
    {
        while (true) {
            $value = self::ask($label, $default);
            if (preg_match($pattern, $value)) {
                return $value;
            }
            self::warning($error);
        }
    }

    private static function ask(string $label, string $default): string
    {
        $suffix = $default !== '' ? ' [' . $default . ']' : '';
        self::write(self::style('? ', self::GREEN . self::BOLD) . $label . $suffix . '：');
        $line = fgets(STDIN);
        if ($line === false) {
            throw new InteractiveInputClosed();
        }
        $value = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $line));
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        return $value !== '' ? $value : $default;
    }

    private static function confirm(string $label, bool $default): bool
    {
        $hint = $default ? 'Y/n' : 'y/N';
        while (true) {
            $answer = strtolower(self::ask($label . ' [' . $hint . ']', ''));
            if ($answer === '') {
                return $default;
            }
            if (in_array($answer, ['y', 'yes', '1', 'true', '是'], true)) {
                return true;
            }
            if (in_array($answer, ['n', 'no', '0', 'false', '否'], true)) {
                return false;
            }
            self::warning('请输入 y 或 n。');
        }
    }

    private static function defaultEncryptedOutput(string $input): string
    {
        $absolute = Filesystem::normalizedAbsolute($input, self::rootPath());
        $name = basename(rtrim(str_replace('\\', '/', $absolute), '/'));
        if ($name === '' || $name === '.') {
            $name = 'project';
        }
        if (is_dir($absolute)) {
            return './build/' . $name . '-encrypted';
        }
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        return './build/' . pathinfo($name, PATHINFO_FILENAME)
            . '.encrypted' . ($extension !== '' ? '.' . $extension : '');
    }

    private static function targetIsWindows(string $platform): bool
    {
        return $platform === 'windows' || ($platform === 'auto' && PHP_OS_FAMILY === 'Windows');
    }

    private static function summary(array $items): void
    {
        self::line();
        self::line('配置摘要', self::BOLD . self::CYAN);
        foreach ($items as $label => $value) {
            self::line('  ' . $label . '：' . $value);
        }
    }

    private static function rootPath(): string
    {
        return defined('ROOT_PATH') ? (string)ROOT_PATH : dirname(__DIR__, 5);
    }

    private static function entryFile(): string
    {
        global $argv;
        $entry = (string)($argv[0] ?? (self::rootPath() . DIRECTORY_SEPARATOR . 'index.php'));
        return Filesystem::normalizedAbsolute($entry, getcwd() ?: self::rootPath());
    }

    private static function outputCallback(): callable
    {
        return static fn(string $message) => self::info($message);
    }

    private static function supportsColor(): bool
    {
        $setting = strtolower((string)getenv('RCMAKER_COLOR'));
        if ($setting === 'always') {
            return true;
        }
        if ($setting === 'never' || getenv('NO_COLOR') !== false || getenv('TERM') === 'dumb') {
            return false;
        }
        $isTty = function_exists('stream_isatty')
            ? @stream_isatty(STDOUT)
            : (function_exists('posix_isatty') && @posix_isatty(STDOUT));
        if (!$isTty) {
            return false;
        }
        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_vt100_support')) {
            return @sapi_windows_vt100_support(STDOUT, true);
        }
        return true;
    }

    private static function header(): void
    {
        self::line();
        self::line('RCMAKER INTERACTIVE', self::BOLD . self::GREEN);
        self::line('交互式项目工具', self::BOLD);
        self::line('项目：' . self::rootPath(), self::DIM);
        self::line('环境：' . PHP_OS_FAMILY . ' / ' . php_uname('m') . ' / PHP ' . PHP_VERSION, self::DIM);
        $devkitVersion = AiDevkit::installedVersion(self::rootPath());
        $devkitStatus = $devkitVersion ?? (AiDevkit::hasManifest(self::rootPath()) ? '版本无法识别' : '未安装');
        self::line('AI DevKit：' . $devkitStatus, self::DIM);
    }

    private static function section(string $title): void
    {
        self::line();
        self::line('== ' . $title . ' ==', self::BOLD . self::CYAN);
    }

    private static function option(string $key, string $label): void
    {
        self::line('  ' . self::style($key . '.', self::GREEN . self::BOLD) . ' ' . $label);
    }

    private static function info(string $message): void
    {
        self::line('[i] ' . $message, self::CYAN);
    }

    private static function success(string $message): void
    {
        self::line('[OK] ' . $message, self::GREEN . self::BOLD);
    }

    private static function warning(string $message): void
    {
        self::line('[!] ' . $message, self::YELLOW);
    }

    private static function error(string $message): void
    {
        self::line('[ERROR] ' . $message, self::RED . self::BOLD);
    }

    private static function line(string $message = '', string $style = ''): void
    {
        fwrite(STDOUT, self::style($message, $style) . PHP_EOL);
    }

    private static function write(string $message): void
    {
        fwrite(STDOUT, $message);
    }

    private static function style(string $message, string $style): string
    {
        return self::$color && $style !== '' ? $style . $message . self::RESET : $message;
    }
}
