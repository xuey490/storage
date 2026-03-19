<?php

declare(strict_types=1);

namespace Framework\Storage\Adapter;

use Framework\Storage\Exception\StorageException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

/**
 * @desc AdapterAbstract 抽象适配器（Symfony 适配版）
 */
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

        // 修改 AdapterAbstract 构造函数中的 Request 处理部分
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

            // 回退：如果仍然没有 Request，尝试用 createFromGlobals()（仅 FPM 环境生效）
            if ($request === null) {
                if (class_exists(Request::class) && !defined('WORKERMAN_ENV')) {
                    $request = Request::createFromGlobals();
                }
            }

            // 最后再检查
            if (!($request instanceof Request)) {
                throw new \RuntimeException('未提供有效的 Symfony Request 实例。Workerman 环境下请确保在启动文件中完成 Request 转换，并传入转换后的 Symfony Request。');
            }
            $files = $request->files->all();

            // 调试：输出原始文件数组
            //error_log('Raw files from request: ' . print_r($files, true));

            // 兼容单文件 & 多文件
            $this->files = $this->normalizeFilesArray($files);
            
            // 调试：输出标准化后的文件数组
            //error_log('Normalized files: ' . print_r($this->files, true));

            $this->verify();
        }
    }

    /**
     * ========== 核心修改2：新增 Workerman Request 转换为 Symfony Request 的方法 ==========
     * 将 Workerman Request 转换为 Symfony Request
     * @param \Workerman\Protocols\Http\Request $wmRequest
     * @return Request
     */
    protected function convertWorkermanRequestToSymfony($wmRequest): Request
    {
        // 1. 获取 Workerman 的原始数据
        $get = $wmRequest->get();
        $post = $wmRequest->post();
        $cookie = $wmRequest->cookie();
        $server = $this->buildServerParams($wmRequest);
        $content = $wmRequest->rawBody();

        // 2. 解析上传文件（关键：Workerman 的文件需要手动解析）
        $files = $this->parseWorkermanUploadFiles($wmRequest);

        // 3. 创建 Symfony Request
        $symRequest = new Request($get, $post, [], $cookie, $files, $server, $content);
        $symRequest->enableHttpMethodParameterOverride();

        return $symRequest;
    }

    /**
     * 解析 Workerman 上传文件，转换为 Symfony UploadedFile 格式
     * @param \Workerman\Protocols\Http\Request $wmRequest
     * @return array
     */
    protected function parseWorkermanUploadFiles($wmRequest): array
    {
        $files = [];
        $wmFiles = $wmRequest->files();

        foreach ($wmFiles as $name => $fileInfo) {
            // 处理多文件上传
            if (is_array($fileInfo['tmp_name'])) {
                $uploadedFiles = [];
                foreach ($fileInfo['tmp_name'] as $key => $tmpName) {
                    if (empty($tmpName) || !is_uploaded_file($tmpName)) {
                        continue;
                    }
                    $uploadedFiles[$key] = new UploadedFile(
                        $tmpName,
                        $fileInfo['name'][$key] ?? '',
                        $fileInfo['type'][$key] ?? '',
                        $fileInfo['error'][$key] ?? UPLOAD_ERR_OK,
                        true // 关键：Workerman 已将文件保存到临时目录，标记为测试模式
                    );
                }
                $files[$name] = $uploadedFiles;
            } else {
                // 单文件上传
                if (empty($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
                    continue;
                }
                $files[$name] = new UploadedFile(
                    $fileInfo['tmp_name'],
                    $fileInfo['name'] ?? '',
                    $fileInfo['type'] ?? '',
                    $fileInfo['error'] ?? UPLOAD_ERR_OK,
                    true // 测试模式标记
                );
            }
        }

        return $files;
    }

    /**
     * 构建 Symfony Request 需要的 SERVER 参数
     * @param \Workerman\Protocols\Http\Request $wmRequest
     * @return array
     */
    protected function buildServerParams($wmRequest): array
    {
        $server = [
            'REQUEST_METHOD' => $wmRequest->method(),
            'REQUEST_URI' => $wmRequest->uri(),
            'QUERY_STRING' => $wmRequest->queryString() ?: '',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'HTTP_HOST' => $wmRequest->host(),
            'CONTENT_TYPE' => $wmRequest->header('Content-Type') ?: '',
            'CONTENT_LENGTH' => $wmRequest->header('Content-Length') ?: '',
            'REMOTE_ADDR' => $wmRequest->connection()->getRemoteIp(),
            'REMOTE_PORT' => $wmRequest->connection()->getRemotePort(),
        ];

        // 补充所有 HTTP 头信息
        foreach ($wmRequest->headers() as $key => $value) {
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
            $server[$serverKey] = $value;
        }

        return $server;
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

    /**
    * 获取配置文件的数组
    */
    private static function getConfig(): array
    {
        if(file_exists(BASE_PATH . '/config/storage.php'))
        {
            $configFile = BASE_PATH . '/config/storage.php';
        }else{
            $configFile = realpath(__DIR__ . '/../config/storage.php');
        }

        $data = require $configFile;

        return $data;
    }

    protected function loadConfig(array $config)
    {
        $data = static::getConfig();
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
            //error_log('Files array is empty in verify() method');
            throw new StorageException('未找到上传文件');
        }

        foreach ($this->files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                //error_log('Invalid file detected: ' . print_r($file, true));
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
            if ($size > $this->singleLimit && $this->singleLimit > 0) {
                throw new StorageException("单文件大小超过限制：{$this->singleLimit}");
            }
            $total += $size;
        }

        if ($total > $this->totalLimit && $this->totalLimit > 0) {
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