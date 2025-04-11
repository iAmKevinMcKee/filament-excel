<?php

namespace pxlrbt\FilamentExcel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

class CleanupTempFilesCommand extends Command
{
    protected $signature = 'filament-excel:cleanup-temp';

    protected $description = 'Clean up temporary Excel files';

    public function handle()
    {
        $tempDiskType = config('filament-excel.temporary_files.disk', 'local');
        
        if ($tempDiskType === 'local') {
            // Clean up local temp files
            $tempPath = config('filament-excel.temporary_files.local_path', storage_path('framework/cache/laravel-excel'));
            
            if (file_exists($tempPath)) {
                $files = glob($tempPath . '/*.xlsx');
                $files = array_merge($files, glob($tempPath . '/*.csv'));
                $files = array_merge($files, glob($tempPath . '/*.xls'));
                
                $count = 0;
                foreach ($files as $file) {
                    if (is_file($file) && (time() - filemtime($file) > 24 * 3600)) {
                        unlink($file);
                        $count++;
                    }
                }
                
                $this->info("Cleaned up {$count} local temporary files.");
            } else {
                $this->info("Temporary directory does not exist: {$tempPath}");
            }
        } else {
            // Clean up remote temp files
            $remoteDisk = config('filament-excel.temporary_files.remote_disk', 's3');
            $remotePrefix = config('filament-excel.temporary_files.remote_prefix', 'temp/excel');
            
            try {
                $files = Storage::disk($remoteDisk)->listContents($remotePrefix, true);
                
                $count = 0;
                foreach ($files as $file) {
                    if ($file->type() === 'file' && $file->lastModified() < now()->subDay()->getTimestamp()) {
                        Storage::disk($remoteDisk)->delete($file->path());
                        $count++;
                    }
                }
                
                $this->info("Cleaned up {$count} remote temporary files from {$remoteDisk} disk.");
            } catch (\Exception $e) {
                $this->error("Failed to clean up remote temporary files: {$e->getMessage()}");
            }
        }
    }
}