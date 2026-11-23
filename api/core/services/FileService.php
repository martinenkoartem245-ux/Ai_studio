<?php

declare(strict_types=1);

class FileService {
    private const ALLOWED_EXTENSIONS = ['txt', 'md', 'json', 'csv', 'php', 'js', 'css', 'py', 'html', 'sql', 'xml', 'yaml', 'yml', 'vue', 'docx'];

    private function getContextDir(string $sessionPath): string {
        return rtrim($sessionPath, '/\\') . DIRECTORY_SEPARATOR . 'context_data' . DIRECTORY_SEPARATOR;
    }

    private function formatFileSize(int $bytes): string {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int)floor(log($bytes, 1024));
        return round($bytes / (1024 ** $i), 1) . ' ' . ($units[$i] ?? 'B');
    }

    public function uploadFiles(string $sessionPath, array $files): array {
        $contextDir = $this->getContextDir($sessionPath);

        if (!is_dir($contextDir)) {
            if (!@mkdir($contextDir, 0755, true) && !is_dir($contextDir)) {
                return ['status' => 'error', 'message' => 'Не удалось создать директорию context_data'];
            }
        }

        if (empty($files) || !isset($files['name'])) {
            return ['status' => 'error', 'message' => 'Файлы не переданы'];
        }

        $fileList = $this->normalizeFilesArray($files);
        $uploaded = [];
        $errors = [];

        foreach ($fileList as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                if ($file['error'] !== UPLOAD_ERR_NO_FILE) {
                    $errors[] = "Ошибка загрузки файла «{$file['name']}»: код {$file['error']}";
                }
                continue;
            }

            $cleanFileName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', basename($file['name']));
            if ($cleanFileName === '' || $cleanFileName === '.' || $cleanFileName === '..') {
                $errors[] = "Недопустимое имя файла: {$file['name']}";
                continue;
            }

            $targetPath = $contextDir . $cleanFileName;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $uploaded[] = $cleanFileName;
            } else {
                $errors[] = "Не удалось сохранить файл {$cleanFileName}";
            }
        }

        if (!empty($errors) && empty($uploaded)) {
            return ['status' => 'error', 'message' => implode('; ', $errors)];
        }

        return [
            'status' => 'success',
            'uploaded' => $uploaded,
            'errors' => $errors
        ];
    }

    // FileService.php — убедитесь, что getSessionFiles НЕ отсекает docx:
public function getSessionFiles(string $sessionPath): array {
    $files = [];
    $contextDir = $this->getContextDir($sessionPath);

    if (!is_dir($contextDir)) {
        return $files;
    }

    $dirFiles = scandir($contextDir);
    if ($dirFiles === false) {
        return $files;
    }

    foreach ($dirFiles as $f) {
        if ($f === '.' || $f === '..') continue;

        $filePath = $contextDir . $f;
        if (is_file($filePath)) {
            $sizeInBytes = filesize($filePath);
            $files[] = [
                'name' => $f,
                'size' => $this->formatFileSize($sizeInBytes !== false ? $sizeInBytes : 0),
                'ext'  => strtolower(pathinfo($f, PATHINFO_EXTENSION))
            ];
        }
    }
    return $files;
    }
 public function getSessionFilesContext(string $sessionPath): string {
        $contextDir = $this->getContextDir($sessionPath);
        if (!is_dir($contextDir)) return '';

        $dirFiles = scandir($contextDir);
        if ($dirFiles === false) return '';

        $content = '';

        foreach ($dirFiles as $f) {
            if ($f === '.' || $f === '..') continue;
            
            $filePath = $contextDir . $f;
            if (is_file($filePath)) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                    $fileText = '';

                    // Специальная обработка для DOCX файлов
                    if ($ext === 'docx') {
                        $fileText = $this->readDocxText($filePath);
                    } else {
                        // Читаем файл ЦЕЛИКОМ (без лимита в 20000 байт)
                        $chunk = @file_get_contents($filePath);
                        if ($chunk !== false && $chunk !== '') {
                            $encoding = mb_detect_encoding($chunk, ['UTF-8', 'Windows-1251', 'ISO-8859-1'], true);
                            if ($encoding && $encoding !== 'UTF-8') {
                                $chunk = mb_convert_encoding($chunk, 'UTF-8', $encoding);
                            }
                            // Убираем mb_substr — берем весь текст
                            $fileText = $chunk;
                        }
                    }

                    if ($fileText !== '') {
                        $content .= "\n\n--- ФАЙЛ: {$f} ---\n" . $fileText;
                    }
                }
            }
        }
        return $content;
    }


    /**
     * Вспомогательный метод для извлечения чистого текста из .docx
     */
    private function readDocxText(string $filePath): string {
        if (!class_exists('ZipArchive')) {
            return '[Не удалось прочитать .docx: расширение zip не установлено в PHP]';
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) === true) {
            if (($index = $zip->locateName('word/document.xml')) !== false) {
                $data = $zip->getFromIndex($index);
                $zip->close();
                
                // Возвращаем текст целиком без mb_substr
                return strip_tags($data);
            }
            $zip->close();
        }
        return '';
    }

    public function deleteFile(string $sessionPath, string $fileName): bool {
        $cleanFileName = basename($fileName);
        if ($cleanFileName === '' || $cleanFileName === '.' || $cleanFileName === '..') {
            return false;
        }

        $filePath = $this->getContextDir($sessionPath) . $cleanFileName;
        if (is_file($filePath)) {
            return @unlink($filePath);
        }
        return false;
    }

    private function normalizeFilesArray(array $files): array {
        $normalized = [];

        if (is_array($files['name'] ?? null)) {
            foreach ($files['name'] as $idx => $name) {
                $normalized[] = [
                    'name' => $name,
                    'type' => $files['type'][$idx] ?? '',
                    'tmp_name' => $files['tmp_name'][$idx] ?? '',
                    'error' => $files['error'][$idx] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $files['size'][$idx] ?? 0,
                ];
            }
        } else {
            $normalized[] = $files;
        }

        return $normalized;
    }

}
