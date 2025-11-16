<?php
declare(strict_types=1);

namespace Framework\Storage\Adapter;

use Framework\Storage\Exception\StorageException;

class LocalAdapter extends AdapterAbstract
{
    public function uploadFile(array $options = []): array
    {
        $result = [];

        $basePath = $this->config['root'] . $this->config['dirname'] . DIRECTORY_SEPARATOR;
        if (!$this->createDir($basePath)) {
            return $this->StorageException('文件夹创建失败，请检查权限');
        }

        $baseUrl = rtrim($this->config['domain'], '/') .
            $this->config['uri'] .
            '/' . $this->config['dirname'] . '/';

		foreach ($this->files as $file) {

			$tmpPath      = $file->getPathname();
			$originalName = $file->getClientOriginalName();

			// === 无依赖获取扩展名 ===
			$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

			// === 无依赖获取文件大小 ===
			$size = filesize($tmpPath);

			// === 原生获取 mime type ===
			$mimeType = mime_content_type($tmpPath);
			// 或者：
			// $mimeType = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpPath);

			// === 计算 hash，也要在 move 前 ===
			$uniqueId = hash_file($this->algo, $tmpPath);
			$saveName = "{$uniqueId}.{$ext}";
			$savePath = $basePath . $saveName;

			// === 移动文件 ===
			$file->move($basePath, $saveName);

			$result[] = [
				'origin_name' => $originalName,
				'save_name'   => $saveName,
				'save_path'   => $savePath,
				'unique_id'   => $uniqueId,
				'url'         => $baseUrl . $saveName,
				'size'        => $size,
				'extension'   => $ext,
				'mime_type'   => $mimeType
			];
		}


        return $this->success($result);
    }

    protected function createDir(string $path): bool
    {
        return is_dir($path) || mkdir($path, 0755, true);
    }

    public function uploadServerFile(string $filePath, string $extension = null)
    {
        if (!file_exists($filePath)) {
            return $this->StorageException("文件不存在：{$filePath}");
        }

        $ext = $extension ?: pathinfo($filePath, PATHINFO_EXTENSION);
        $unique = hash_file($this->algo, $filePath);

        $saveName = "{$unique}.{$ext}";
        $savePath = $this->config['root'] . $this->config['dirname'] . '/' . $saveName;

        $this->createDir(dirname($savePath));

        copy($filePath, $savePath);

        $url = rtrim($this->config['domain'], '/') .
            $this->config['uri'] . '/' .
            $this->config['dirname'] . '/' . $saveName;

        return $this->success([
            'origin_name' => basename($filePath),
            'save_name'   => $saveName,
            'save_path'   => $savePath,
            'unique_id'   => $unique,
            'url'         => $url
        ]);
    }
	

	
}
