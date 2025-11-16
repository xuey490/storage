<?php
declare(strict_types=1);

namespace Framework\Storage\Adapter;

use Exception;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class LocalAdapter extends AdapterAbstract
{
    /**
     * @desc 上传文件（支持多文件）
     */
    public function uploadFile(array $options = []): array
    {
        try {
            $basePath = $this->normalizePath(
                $this->config['root'] . $this->config['dirname'] . DIRECTORY_SEPARATOR
            );

            if (!$this->createDir($basePath)) {
                throw new RuntimeException('文件夹创建失败，请检查权限：' . $basePath);
            }

            $baseUrl = rtrim($this->config['domain'], '/') .
                $this->config['uri'] .
                '/' . $this->config['dirname'] . '/';

            $results = [];

            foreach ($this->files as $file) {
                if (!$file instanceof UploadedFile) {
                    throw new RuntimeException("上传文件对象不是 UploadedFile 实例");
                }

                $tmpPath      = $file->getPathname();
                $originalName = $file->getClientOriginalName();

                // ====== 1. MIME 白名单检查 ======
                $mimeType = $this->getMimeType($tmpPath);

                if (!empty($this->config['mime_whitelist'])
                    && !in_array($mimeType, $this->config['mime_whitelist'], true)
                ) {
                    throw new RuntimeException("文件 MIME 不允许上传: {$mimeType}");
                }

                // ====== 2. 安全获取扩展 ======
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                // ====== 3. 文件大小 ======
                $size = filesize($tmpPath);

                // ====== 4. 双重 hash（安全性更高） ======
                $hashSha1 = sha1_file($tmpPath);
                $hashMd5  = md5_file($tmpPath);
                $uniqueId = $hashSha1 . '_' . $hashMd5;

                // ====== 5. 文件名随机化 ======
                $randomPrefix = bin2hex(random_bytes(5)); // 10 字节随机
                $saveName = "{$randomPrefix}_{$uniqueId}.{$ext}";

                $savePath = $this->normalizePath($basePath . $saveName);

                // ====== 6. 移动文件 ======
                $file->move($basePath, $saveName);

                // ====== 7. 保存结果 ======
                $results[] = [
                    'origin_name' => $originalName,
                    'save_name'   => $saveName,
                    'save_path'   => $savePath,
                    'unique_id'   => $uniqueId,
                    'sha1'        => $hashSha1,
                    'md5'         => $hashMd5,
                    'url'         => $baseUrl . $saveName,
                    'size'        => $size,
                    'extension'   => $ext,
                    'mime_type'   => $mimeType,
                ];
            }

            return $this->success($results);

        } catch (Exception $e) {
            return $this->StorageException($e->getMessage());
        }
    }


    /**
     * @desc 原生获取 MIME（无 symfony/mime 依赖）
     */
    protected function getMimeType(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $path);
            finfo_close($finfo);
            return $mime ?: 'application/octet-stream';
        }

        if (function_exists('mime_content_type')) {
            return mime_content_type($path) ?: 'application/octet-stream';
        }

        return 'application/octet-stream';
    }


    /**
     * @desc 创建目录（Windows/Linux 兼容）
     */
    protected function createDir(string $path): bool
    {
        if (!is_dir($path)) {
            return mkdir($path, 0755, true);
        }
        return true;
    }


    /**
     * @desc 规范化路径（兼容 Windows 路径分隔符）
     */
    protected function normalizePath(string $path): string
    {
        return str_replace(['\\', '//'], '/', $path);
    }
	
	
	public function uploadServerFile(string $filePath, string $extension = null)
	{
		try {
			if (!file_exists($filePath)) {
				throw new StorageException("文件不存在：{$filePath}");
			}

			$ext = $extension ?: strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

			// 计算唯一 hash（支持 md5 或 sha1）
			$unique = hash_file($this->algo, $filePath);

			// 构造保存路径
			$basePath = rtrim($this->config['root'] . $this->config['dirname'], '/') . '/';
			$this->createDir($basePath);

			$saveName = "{$unique}.{$ext}";
			$savePath = $basePath . $saveName;

			// 写入磁盘
			if (!copy($filePath, $savePath)) {
				throw new StorageException("文件保存失败：{$savePath}");
			}

			// 构造访问 URL
			$url = rtrim($this->config['domain'], '/') .
				$this->config['uri'] . '/' .
				$this->config['dirname'] . '/' . $saveName;

			return $this->success([
				'origin_name' => basename($filePath),
				'save_name'   => $saveName,
				'save_path'   => $savePath,
				'unique_id'   => $unique,
				'url'         => $url,
				'extension'   => $ext,
			]);

		} catch (\Throwable $e) {
			return $this->error($e->getMessage());
		}
	}

}
