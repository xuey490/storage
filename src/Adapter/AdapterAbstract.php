<?php
/**
 * @desc AdapterAbstract 抽象适配器（Symfony 适配版）
 */

declare(strict_types=1);

namespace Framework\Storage\Adapter;

use Framework\Storage\Exception\StorageException;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;


abstract class AdapterAbstract implements AdapterInterface
{


    protected bool $_isFileUpload;
    protected array $files = [];
    protected array $config;

    protected array $includes = [];
    protected array $excludes = [];
    protected int $singleLimit = 0;
    protected int $totalLimit = 0;
    protected int $nums = 0;
    protected string $algo = 'md5';

    public function __construct(array $config = [])
    {
        $this->loadConfig($config);

        $this->_isFileUpload = $config['_is_file_upload'] ?? true;

        if ($this->_isFileUpload) {
			
			// 优先使用传入的 request（建议）
			$request = $config['request'] ?? null;

			// 如果传入的是 RequestStack（常见于 Symfony），尝试取当前 Request
			if ($request === null && isset($config['request_stack'])) {
				$rs = $config['request_stack'];
				if ($rs instanceof \Symfony\Component\HttpFoundation\RequestStack) {
					$request = $rs->getCurrentRequest();
				}
			}

			// 回退：如果仍然没有 Request，尝试用 createFromGlobals()
			if ($request === null) {
				if (class_exists(Request::class)) {
					$request = Request::createFromGlobals();
				}
			}

			// 最后再检查
			if (!($request instanceof Request)) {
				// 明确的异常信息，便于定位
				throw new \RuntimeException('未提供有效的 Symfony Request 实例。请在 Storage::disk(...) 或 Adapter 构造时通过 config[\'request\'] 传入 Request 实例，或传入 RequestStack（config[\'request_stack\']）.');
			}
            $files = $request->files->all();

            // 兼容单文件 & 多文件
            $this->files = $this->normalizeFilesArray($files);

            $this->verify();
        }
    }

    /**
     * 将多维文件结构转换为一维 UploadedFile[]
     */
	protected function normalizeFilesArray(array $files): array
	{
		$result = [];

		foreach ($files as $key => $file) {

			// null / 空字段跳过
			if ($file === null) {
				continue;
			}

			// 单文件
			if ($file instanceof UploadedFile) {
				$result[] = $file;
				continue;
			}

			// 多文件数组
			if (is_array($file)) {
				foreach ($file as $f) {
					if ($f instanceof UploadedFile) {
						$result[] = $f;
					}
				}
			}
		}

		return $result;
	}


    protected function loadConfig(array $config)
    {

        $ConfigInit =  new \Framework\Config\ConfigLoader(BASE_PATH . '/config' , 'storage.php');
		$data = $ConfigInit->loadAll();
		$default = $data;

        $this->includes    = $config['include']      ?? $default['include'];
        $this->excludes    = $config['exclude']      ?? $default['exclude'];
        $this->singleLimit = $config['single_limit'] ?? $default['single_limit'];
        $this->totalLimit  = $config['total_limit']  ?? $default['total_limit'];
        $this->nums        = $config['nums']         ?? $default['nums'];
        $this->algo        = $config['algo']         ?? $this->algo;

        $this->config = $config + [
            'includes'     => $this->includes,
            'excludes'     => $this->excludes,
            'single_limit' => $this->singleLimit,
            'total_limit'  => $this->totalLimit,
            'nums'         => $this->nums,
            'algo'         => $this->algo,
        ];

        if (isset($this->config['dirname']) && is_callable($this->config['dirname'])) {
            $this->config['dirname'] = (string)$this->config['dirname']() ?: $this->config['dirname'];
        }
    }

    protected function verify()
    {
        if (empty($this->files)) {
            throw new StorageException('未找到上传文件');
        }

        foreach ($this->files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new StorageException('无效文件');
            }
        }

        $this->allowedFile();
        $this->allowedFileSize();
    }

    protected function allowedFile()
    {
        foreach ($this->files as $file) {
            $ext = strtolower($file->getClientOriginalExtension());

            if ($this->includes && !in_array($ext, $this->includes, true)) {
                throw new StorageException("扩展名不合法：{$ext}");
            }

            if ($this->excludes && in_array($ext, $this->excludes, true)) {
                throw new StorageException("禁止上传的扩展名：{$ext}");
            }
        }
    }

    protected function allowedFileSize()
    {
        if (count($this->files) > $this->nums) {
            throw new StorageException("文件数量超出限制：{$this->nums}");
        }

        $total = 0;

        foreach ($this->files as $file) {
            $size = $file->getSize();
            if ($size > $this->singleLimit) {
                throw new StorageException("单文件大小超过限制：{$this->singleLimit}");
            }
            $total += $size;
        }

        if ($total > $this->totalLimit) {
            throw new StorageException("总大小超过限制：{$this->totalLimit}");
        }
    }

    /**
     * 默认 Base64 上传实现
     */
    public function uploadBase64(string $base64, string $extension = 'png')
    {
        if (!preg_match('/^data:\w+\/\w+;base64,/', $base64)) {
            return $this->error("Base64 格式不正确");
        }

        $data = substr($base64, strpos($base64, ',') + 1);
        $binary = base64_decode($data);

        if ($binary === false) {
            return $this->error("Base64 解码失败");
        }

        $temp = tempnam(sys_get_temp_dir(), 'up_');
        file_put_contents($temp, $binary);

        return $this->uploadServerFile($temp, $extension);
    }
	
    /**
     * 统一成功返回
     */
    protected function success($data = [], string $msg = 'success')
    {
        return [
            'success' => true,
            'message' => $msg,
            'data'    => $data
        ];
    }

    /**
     * 统一错误返回
     */
    protected function error(string $msg)
    {
        return [
            'success' => false,
            'message' => $msg,
            'data'    => []
        ];
    }	
}