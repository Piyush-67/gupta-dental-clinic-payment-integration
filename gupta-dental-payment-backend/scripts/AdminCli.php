<?php
declare(strict_types=1);

final class AdminCli
{
    public static function requireCli(): void
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code(404);
            exit;
        }
    }

    public static function readLine(string $prompt): string
    {
        fwrite(STDOUT, $prompt);
        $value = fgets(STDIN);

        if ($value === false) {
            throw new RuntimeException('Unable to read command-line input.');
        }

        return trim($value);
    }

    public static function readConfirmedPassword(): string
    {
        $password = self::readHiddenPassword('Administrator password');
        try {
            $confirmation = self::readHiddenPassword(
                'Confirm administrator password'
            );
            if (!hash_equals($password, $confirmation)) {
                throw new InvalidArgumentException('Passwords do not match.');
            }
        } catch (Throwable $e) {
            self::clearPassword($password);
            if (isset($confirmation)) {
                self::clearPassword($confirmation);
            }
            throw $e;
        }

        self::clearPassword($confirmation);
        return $password;
    }

    public static function clearPassword(string &$password): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($password);
        } else {
            $password = '';
        }
    }

    private static function readHiddenPassword(string $prompt): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return self::readWindowsPassword($prompt);
        }

        return self::readPosixPassword($prompt);
    }

    private static function readWindowsPassword(string $prompt): string
    {
        $quotedPrompt = "'" . str_replace("'", "''", $prompt) . "'";
        $script = '$secure = Read-Host -Prompt ' . $quotedPrompt .
            ' -AsSecureString; ' .
            '$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure); ' .
            'try { $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr); ' .
            '[Console]::Write([Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($plain))) } ' .
            'finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }';

        $pipes = [];
        $process = proc_open(
            ['powershell.exe', '-NoProfile', '-Command', $script],
            [
                0 => ['file', 'NUL', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to open the secure password prompt.');
        }

        $encoded = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || !is_string($encoded)) {
            throw new RuntimeException(
                $error !== '' ? trim($error) : 'The secure password prompt failed.'
            );
        }

        $password = base64_decode(trim($encoded), true);
        if (!is_string($password)) {
            throw new RuntimeException('The secure password prompt returned invalid data.');
        }

        return $password;
    }

    private static function readPosixPassword(string $prompt): string
    {
        $tty = fopen('/dev/tty', 'r+');
        if ($tty === false) {
            throw new RuntimeException('A terminal is required for password entry.');
        }

        $state = self::runStty(['-g']);
        self::runStty(['-echo']);
        try {
            fwrite($tty, $prompt . ': ');
            $password = fgets($tty);
            if ($password === false) {
                throw new RuntimeException('Unable to read the secure password prompt.');
            }
        } finally {
            self::runStty([$state]);
            fwrite($tty, PHP_EOL);
            fclose($tty);
        }

        return rtrim($password, "\r\n");
    }

    private static function runStty(array $arguments): string
    {
        $pipes = [];
        $process = proc_open(
            array_merge(['stty'], $arguments),
            [
                0 => ['file', '/dev/tty', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to configure the terminal password prompt.');
        }

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                $error !== '' ? trim($error) : 'Unable to configure the terminal.'
            );
        }

        return trim((string) $output);
    }
}
