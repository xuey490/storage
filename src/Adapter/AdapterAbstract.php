<?php
/**
 * @desc AdapterAbstract 抽象适配器（Symfony 适配版）
 */
declare(strict_types=1);

namespace Framework\Storage\Adapter;

use Framework\Storage\Exception\StorageException;
use Framework\Storage\Traits\ErrorMsg;
use Symfony\Component\HttpFoundation\File\UploadedFile;

abstract class AdapterAbstract implements AdapterInterface
{
    use ErrorMsg;

    /**
     * @var bool
     */
    public $_isFileUpload;

    /**
     * @var string
     */
    public $dirSeparator = DIRECTORY_SEPARATOR;

    /**
     * @var UploadedFile[]
     */
    protected $files;

    protected $includes;
	
    protected $excludes;
	
    protected $singleLimit;
	
    protected $totalLimit;
	
    protected $nums;

    protected $config;

    /**
     * @var string
     */
    protected $algo = 'md5';

    /**
     * AdapterAbstract constructor.
     */
    public function __construct(array $config = [])
    {
        $this->loadConfig($config);

        $this->dirSeparator = \DIRECTORY_SEPARATOR === '\\' ? '/' : DIRECTORY_SEPARATOR;

        $this->_isFileUpload = $config['_is_file_upload'] ?? true;

        if ($this->_isFileUpload) {
            // ✔ Symfony request 获取文件
            $request = request(); // 必须返回 Symfony Request
            $this->files = $request->files->all();

            $this->includes = [];
            $this->excludes = [];
            $this->singleLimit = 0;
            $this->totalLimit = 0;
            $this->nums = 0;

            $this->verify();
        }
    }

    public function uploadBase64(string $base64, string $extension = 'png')
    {
        return $this->setError(false, '暂不支持');
    }

    public function uploadServerFile(string $file_path)
    {
        return $this->setError(false, '暂不支持');
    }

    protected function loadConfig(array $config)
    {
        $defaultConfig = config('plugin.tinywan.storage.app.storage');

        $this->includes     = $config['include']      ?? $defaultConfig['include'];
        $this->excludes     = $config['exclude']      ?? $defaultConfig['exclude'];
        $this->singleLimit  = $config['single_limit'] ?? $defaultConfig['single_limit'];
        $this->totalLimit   = $config['total_limit']  ?? $defaultConfig['total_limit'];
        $this->nums         = $config['nums']         ?? $defaultConfig['nums'];
        $this->algo         = $config['algo']         ?? $this->algo;

        $this->config = $config + [
            'includes'     => $this->includes,
            'excludes'     => $this->excludes,
            'single_limit' => $this->singleLimit,
            'total_limit'  => $this->totalLimit,
            'nums'         => $this->nums,
            'algo'         => $this->algo,
        ];

        if (isset($this->config['dirname']) && is_callable($this->config['dirname'])) {
            $this->config['dirname'] = (string) $this->config['dirname']() ?: $this->config['dirname'];
        }
    }

    /**
     * 文件验证（Symfony 版本）
     */
    protected function verify()
    {
        if (!$this->files) {
            throw new StorageException('未找到符合条件的文件资源');
        }

        foreach ($this->files as $file) {
            if (!$file instanceof UploadedFile) {
                throw new StorageException('无效的文件类型');
            }

            if (!$file->isValid()) {
                throw new StorageException('未选择文件或者无效的文件');
            }
        }

        $this->allowedFile();
        $this->allowedFileSize();
    }

    /**
     * 获取文件大小
     */
    protected function getSize(UploadedFile $file): int
    {
        return $file->getSize();
    }

    /**
     * 允许上传文件类型校验（Symfony 版本）
     */
    protected function allowedFile(): bool
    {
        foreach ($this->files as $file) {
            /** @var UploadedFile $file */
            $fileName = $file->getClientOriginalName();

            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            // include 模式
            if (!empty($this->config['includes'])) {
                if (!in_array($ext, $this->config['includes'])) {
                    throw new StorageException($fileName . '，文件扩展名不合法');
                }
            }

            // exclude 模式
            if (!empty($this->config['excludes'])) {
                if (in_array($ext, $this->config['excludes'])) {
                    throw new StorageException($fileName . '，文件扩展名不合法');
                }
            }
        }

        return true;
    }

    /**
     * 文件大小校验（Symfony 版本）
     */
    protected function allowedFileSize()
    {
        $fileCount = count($this->files);

        if ($fileCount > $this->config['nums']) {
            throw new StorageException('文件数量过多，超出系统文件数量限制');
        }

        $totalSize = 0;

        foreach ($this->files as $file) {
            $size = $this->getSize($file);

            if ($size > $this->config['single_limit']) {
                throw new StorageException(
                    $file->getClientOriginalName() . '，单文件大小已超出系统限制：' . $this->config['single_limit']
                );
            }

            $totalSize += $size;
        }

        if ($totalSize > $this->config['total_limit']) {
            throw new StorageException('总文件大小已超出系统最大限制：' . $this->config['total_limit']);
        }
    }
}
