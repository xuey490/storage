<?php
declare(strict_types=1);

namespace Framework\Storage;

use Framework\Storage\Exception\StorageException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;


class Storage
{
    public const MODE_LOCAL = 'local';
    public const MODE_OSS = 'oss';
    public const MODE_COS = 'cos';
    public const MODE_QINIU = 'qiniu';
    public const MODE_S3 = 's3';

    /**
     * 允许的存储方式
     */
    public static array $allowStorage = [
        self::MODE_LOCAL,
        self::MODE_OSS,
        self::MODE_COS,
        self::MODE_QINIU,
        self::MODE_S3
    ];

    /**
     * 获取适配器实例
     */
    public static function disk(
		string $name = null, 
		bool $isFileUpload = true , 
		Request $request = null , 
		?RequestStack $requestStack = null
	)
    {
        $storageName = $name ?: self::getDefaultStorage();  // local / oss / cos...

        if (!in_array($storageName, self::$allowStorage, true)) {
            throw new StorageException("不支持的存储方式：{$storageName}");
        }

        $config = self::getStorageConfig($storageName);

		//$class = self::resolveAdapter($config['adapter']);

        if (!isset($config['adapter']) || !class_exists($config['adapter'])) {
            throw new StorageException("适配器不存在：{$config['adapter']}");
        }
		
		// 如果外面传了 Request，注入
		if ($request instanceof Request) {
			$config['request'] = $request;
		}

		// 如果传了 RequestStack，也注入
		if ($requestStack instanceof RequestStack) {
			$config['request_stack'] = $requestStack;
		}

        return new $config['adapter'](array_merge(
            $config,
            ['_is_file_upload' => $isFileUpload]
        ));
    }

	private static function resolveAdapter(string $adapter): string
	{
		return "Framework\\Storage\\Adapter\\" . ucfirst($adapter) . "Adapter";
	}
	

	/**
	* 获取配置文件的数组
	*/
	private static function getConfig(): array
	{
		if(file_exists(BASE_PATH . '/config/storage.php'))
		{
			$configFile = BASE_PATH . '/config/storage.php';
		}else{
			$configFile = __DIR__ . '/config/storage.php';
		}

        $ConfigInit =  new \Framework\Config\ConfigLoader( $configFile );
		$data = $ConfigInit->loadAll() ?? config('storage');
		if($data != null ){
			return $data;
		}
		return config('storage');
	}

    /**
     * 获取默认存储值（local / oss / cos ...）
     */
    public static function getDefaultStorage(): string
    {
        $data = static::getConfig();
		$default = $data['default'];

		return $default ?? self::MODE_LOCAL;
    }

    /**
     * 获取具体存储配置
     */
    public static function getStorageConfig(string $name): array
    {
		
        $data = static::getConfig();
		
		$config = $data[$name] ?? null;

        if (!$config || !is_array($config)) {
            throw new StorageException("未找到存储配置：storage.{$name}");
        }

        return $config;
    }

    /**
     * 静态转发（uploadFile / uploadBase64 / uploadServerFile）
     */
    public static function __callStatic($name, $arguments)
    {
        return static::disk()->{$name}(...$arguments);
    }
}
