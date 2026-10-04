<?php

namespace App\Services;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Throwable;

/** Keep private files in step with the database work that references them. */
class StoredFileTransaction
{
    /** @var array<int, array<int, string>> */
    private array $paths = [];

    public function run(Closure $callback): mixed
    {
        $this->paths[] = [];

        try {
            $result = DB::transaction($callback);
            $committedPaths = array_pop($this->paths);
            if ($this->paths !== []) {
                array_push($this->paths[array_key_last($this->paths)], ...$committedPaths);
            }

            return $result;
        } catch (Throwable $exception) {
            $rolledBackPaths = array_pop($this->paths);
            if ($rolledBackPaths !== []) {
                Storage::disk('local')->delete($rolledBackPaths);
            }

            throw $exception;
        }
    }

    public function store(UploadedFile $file, string $directory): string
    {
        if ($this->paths === []) {
            throw new LogicException('A private file must be stored inside a tracked transaction.');
        }

        $path = $file->store($directory, 'local');
        if ($path === false) {
            throw new RuntimeException('Could not store the private file.');
        }
        $this->paths[array_key_last($this->paths)][] = $path;

        return $path;
    }
}
