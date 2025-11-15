<?php
/**
 * @desc StorageService
 *
 */
declare(strict_types=1);

namespace Framework\Storage;


/**
 * @see Storage
 * @mixin Storage
 *
 * @method static array uploadFile(array $config = [])  上传文件
 * @method static array uploadBase64(string $base64, string $extension = 'png') 上传Base64文件
 * @method static array uploadServerFile(string $file_path)  上传服务端文件
 */
class Storage
{
    /**
     * 本地对象存储.
     */
    public const MODE_LOCAL = 'local';

    /**
     * 阿里云对象存储.
     */
    public const MODE_OSS = 'oss';

    /**
     * 腾讯云对象存储.
     */
    public const MODE_COS = 'cos';

    /**
     * 七牛云对象存储.
     */
    public const MODE_QINIU = 'qiniu';

    /**
     * S3对象存储.
     */
    public const MODE_S3 = 's3';

    /**
     * Support Storage
     */
    static $allowStorage = [
        self::MODE_LOCAL,
        self::MODE_OSS,
        self::MODE_COS,
        self::MODE_QINIU,
        self::MODE_S3
    ];

    /**
     * @desc 存储磁盘
     * @param string|null $name
     * @param bool $_is_file_upload
     * @return mixed
     * 
     */
    public static function disk(string $name = null, bool $_is_file_upload = true)
    {
        $storage = $name ?? self::getDefaultStorage();
        $config = self::getConfig($storage);
        return new $config['adapter'](array_merge(
            $config, ['_is_file_upload' => $_is_file_upload]
        ));
    }

    /**
     * @desc: 默认存储
     * @return mixed
     * 
     */
    public static function getDefaultStorage()
    {
        return self::getConfig('default');
    }

    /**
     * @desc: 获取存储配置
     * @param string|null $name 名称
     * @return mixed
     * 
     */
    public static function getConfig(string $name = null)
    {
        if (!is_null($name)) {
            return config('storage.' . $name, self::MODE_LOCAL);
        }
        return config('storage.default');
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     * 
     */
    public static function __callStatic($name, $arguments)
    {
        return static::disk()->{$name}(...$arguments);
    }
}
