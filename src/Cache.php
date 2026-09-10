<?php

function cacheEnvironmentValue($name)
{
    return $_ENV[$name] ?? getenv($name) ?: $_ENV[strtolower($name)] ?? getenv(strtolower($name)) ?: '';
}

function cacheRedisClient()
{
    $redisHost = cacheEnvironmentValue('REDIS_HOST');
    if ($redisHost === '' || !class_exists('Predis\\Client')) {
        return null;
    }

    $parameters = [
        'scheme' => 'tcp',
        'host' => $redisHost,
        'port' => (int) (cacheEnvironmentValue('REDIS_PORT') ?: 6379),
    ];
    $redisPassword = cacheEnvironmentValue('REDIS_PASSWORD');
    if ($redisPassword !== '') {
        $parameters['password'] = $redisPassword;
    }

    return new Predis\Client($parameters);
}

function cacheFilePath($cacheKey, $path = '')
{
    $directory = __DIR__ . '/../cache' . ($path !== '' ? '/' . trim($path, '/') : '');
    return [$directory, $directory . '/' . $cacheKey . '.cache'];
}

function getCache($cacheKey, $time = 3600, $path = '', $isCacheByFile = false)
{
    try {
        $redis = cacheRedisClient();
        if ($redis !== null) {
            $serializedData = $redis->get($cacheKey);
            return $serializedData === null ? false : unserialize($serializedData, ['allowed_classes' => false]);
        }
    } catch (Throwable $error) {
        return false;
    }

    [, $cacheFile] = cacheFilePath($cacheKey, $path);
    if (!is_file($cacheFile)) {
        return false;
    }
    if (filemtime($cacheFile) < time() - $time) {
        unlink($cacheFile);
        return false;
    }

    return unserialize(file_get_contents($cacheFile), ['allowed_classes' => false]);
}

function removeCache($cacheKey, $path = '')
{
    try {
        $redis = cacheRedisClient();
        if ($redis !== null) {
            $redis->del([$cacheKey]);
            return;
        }
    } catch (Throwable $error) {
        return;
    }

    [, $cacheFile] = cacheFilePath($cacheKey, $path);
    if (is_file($cacheFile)) {
        unlink($cacheFile);
    }
}

function saveCache($cacheKey, $cacheData, $path = '', $time = 3600, $isCacheByFile = false)
{
    try {
        $redis = cacheRedisClient();
        if ($redis !== null) {
            $redis->setex($cacheKey, $time, serialize($cacheData));
            return;
        }
    } catch (Throwable $error) {
        return;
    }

    [$directory, $cacheFile] = cacheFilePath($cacheKey, $path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível criar o diretório de cache.');
    }
    file_put_contents($cacheFile, serialize($cacheData), LOCK_EX);
}

function clearCache($dir = '')
{
    if (!empty($dir)) {
        $dir = __DIR__ . '/../cache/' . trim($dir, '/');
    } else {
        $dir = __DIR__ . '/../cache/';
    }

    if (is_dir($dir)) {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            if (is_dir("$dir/$file")) {
                clearCache("$file");
            } else {
                unlink("$dir/$file");
            }
        }
        return rmdir($dir);
    }
}


function deleteDir(string $dirPath): void
{
    if (! is_dir($dirPath)) {
        throw new InvalidArgumentException("$dirPath must be a directory");
    }
    if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
        $dirPath .= '/';
    }
    $files = glob($dirPath . '*', GLOB_MARK);
    foreach ($files as $file) {
        if (is_dir($file)) {
            deleteDir($file);
        } else {
            unlink($file);
        }
    }
    rmdir($dirPath);
}
