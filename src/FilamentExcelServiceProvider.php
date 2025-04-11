<?php

namespace pxlrbt\FilamentExcel;

use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use pxlrbt\FilamentExcel\Commands\PruneExportsCommand;
use pxlrbt\FilamentExcel\Events\ExportFinishedEvent;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentExcelServiceProvider extends PackageServiceProvider
{
    public function register(): void
    {
        // Publish and merge configuration
        $this->mergeConfigFrom(
            __DIR__.'/../config/filament-excel.php', 'filament-excel'
        );

        // Get disk settings from config or use defaults
        $diskName = config('filament-excel.disk', 'filament-excel');
        $diskDriver = config('filament-excel.disk_driver', 'local');
        $diskConfig = [];

        // If using local disk, set default local disk config
        if ($diskDriver === 'local') {
            $diskConfig = [
                'driver' => 'local',
                'root' => storage_path('app/filament-excel'),
                'url' => config('app.url').'/filament-excel',
            ];
        }
        // If using S3, inherit the S3 configuration from the existing s3 disk
        elseif ($diskDriver === 's3' && config()->has('filesystems.disks.s3')) {
            $s3Config = config('filesystems.disks.s3');
            $diskConfig = array_merge($s3Config, [
                'root' => config('filament-excel.s3_path', 'filament-excel'),
            ]);
        }

        // Set the disk configuration
        config()->set("filesystems.disks.{$diskName}", $diskConfig);

        // Configure Laravel Excel's temporary files storage
        $tempDiskType = config('filament-excel.temporary_files.disk', 'local');
        
        if ($tempDiskType === 'local') {
            // Configure local temp path
            $tempPath = config('filament-excel.temporary_files.local_path', storage_path('framework/cache/laravel-excel'));
            config()->set('excel.temporary_files.local_path', $tempPath);
            
            // Ensure local directory exists with proper permissions
            if (!file_exists($tempPath)) {
                try {
                    mkdir($tempPath, 0755, true);
                } catch (\Exception $e) {
                    // Will be created when needed, so just log warning for now
                    \Illuminate\Support\Facades\Log::warning("Failed to create Laravel Excel temp directory: {$e->getMessage()}");
                }
            } elseif (!is_writable($tempPath)) {
                try {
                    chmod($tempPath, 0755);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("Failed to make Laravel Excel temp directory writable: {$e->getMessage()}");
                }
            }
            
            // Always ensure Laravel Excel uses direct disk writing to avoid fopen() issues
            config()->set('excel.temporary_files.force_disk_write', true);
            
        } else {
            // Configure remote disk for temp files
            $remoteDisk = config('filament-excel.temporary_files.remote_disk', 's3');
            $remotePrefix = config('filament-excel.temporary_files.remote_prefix', 'temp/excel');
            
            // Update Laravel Excel config
            config()->set('excel.temporary_files.remote_disk', $remoteDisk);
            config()->set('excel.temporary_files.remote_prefix', $remotePrefix);
            
            // Enable remote temporary files
            config()->set('excel.temporary_files.use_remote', true);
        }

        parent::register();
    }

    public function configurePackage(Package $package): void
    {
        $package->name('filament-excel')
            ->hasConfigFile()
            ->hasCommands([
                PruneExportsCommand::class,
                Commands\CleanupTempFilesCommand::class,
            ])
            ->hasRoutes(['web'])
            ->hasTranslations();
    }

    public function bootingPackage()
    {
        Filament::serving(fn () => app(FilamentExport::class)->sendNotification());

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(PruneExportsCommand::class)->daily();
        });

        Event::listen(ExportFinishedEvent::class, [$this, 'cacheExportFinishedNotification']);
    }

    public function cacheExportFinishedNotification(ExportFinishedEvent $event): void
    {
        if ($event->userId === null) {
            return;
        }

        $key = FilamentExport::getNotificationCacheKey($event->userId);

        $exports = cache()->pull($key, []);
        $exports[] = [
            'id' => Str::uuid(),
            'filename' => $event->filename,
            'userId' => $event->userId,
            'locale' => $event->locale,
        ];

        cache()->put($key, $exports);
    }
}