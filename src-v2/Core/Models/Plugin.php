<?php
/**
 * Plugin State Model for KISS Smart Batch Installer v2
 * 
 * Represents the state and available actions for a GitHub repository
 * that may or may not be a WordPress plugin.
 */

namespace KissSmartBatchInstaller\V2\Core\Models;

class Plugin
{
    // Plugin states
    public const STATE_UNKNOWN = 'unknown';
    public const STATE_CHECKING = 'checking';
    public const STATE_AVAILABLE = 'available';
    public const STATE_INSTALLED_INACTIVE = 'installed_inactive';
    public const STATE_INSTALLED_ACTIVE = 'installed_active';
    public const STATE_NOT_PLUGIN = 'not_plugin';
    public const STATE_ERROR = 'error';

    private string $repositoryName;
    private string $state = self::STATE_UNKNOWN;
    private ?string $pluginFile = null;
    private ?string $settingsUrl = null;
    private ?string $errorMessage = null;
    private array $metadata = [];

    public function __construct(string $repositoryName)
    {
        $this->repositoryName = $repositoryName;
    }

    /**
     * Check if this plugin can be installed
     */
    public function isInstallable(): bool
    {
        return $this->state === self::STATE_AVAILABLE;
    }

    /**
     * Check if this plugin is installed (active or inactive)
     */
    public function isInstalled(): bool
    {
        return in_array($this->state, [
            self::STATE_INSTALLED_INACTIVE,
            self::STATE_INSTALLED_ACTIVE
        ]);
    }

    /**
     * Check if this plugin is active
     */
    public function isActive(): bool
    {
        return $this->state === self::STATE_INSTALLED_ACTIVE;
    }

    /**
     * Get human-readable state label
     */
    public function getStateLabel(): string
    {
        return match($this->state) {
            self::STATE_INSTALLED_ACTIVE => __('Active', 'kiss-smart-batch-installer'),
            self::STATE_INSTALLED_INACTIVE => __('Inactive', 'kiss-smart-batch-installer'),
            self::STATE_NOT_PLUGIN => __('Not a WordPress Plugin', 'kiss-smart-batch-installer'),
            self::STATE_ERROR => __('Error', 'kiss-smart-batch-installer'),
            self::STATE_CHECKING => __('Checking...', 'kiss-smart-batch-installer'),
            default => ''
        };
    }

    /**
     * Get available action buttons based on current state
     */
    public function getActionButtons(): array
    {
        return match($this->state) {
            self::STATE_AVAILABLE => [
                ['type' => 'install', 'text' => __('Install', 'kiss-smart-batch-installer'), 'primary' => true],
            ],
            self::STATE_INSTALLED_INACTIVE => [
                ['type' => 'activate', 'text' => __('Activate', 'kiss-smart-batch-installer'), 'primary' => true],
                ['type' => 'settings', 'text' => __('Settings', 'kiss-smart-batch-installer'), 'url' => $this->settingsUrl, 'condition' => !empty($this->settingsUrl)]
            ],
            self::STATE_INSTALLED_ACTIVE => [
                ['type' => 'deactivate', 'text' => __('Deactivate', 'kiss-smart-batch-installer')],
                ['type' => 'settings', 'text' => __('Settings', 'kiss-smart-batch-installer'), 'url' => $this->settingsUrl, 'condition' => !empty($this->settingsUrl)]
            ],
            self::STATE_NOT_PLUGIN => [],
            self::STATE_ERROR => [
                ['type' => 'retry', 'text' => __('Retry', 'kiss-smart-batch-installer'), 'secondary' => true]
            ],
            self::STATE_CHECKING => [],
            default => [
                ['type' => 'check', 'text' => __('Check Status', 'kiss-smart-batch-installer'), 'primary' => true]
            ]
        };
    }

    // Getters and setters
    public function setState(string $state, ?string $errorMessage = null): void
    {
        $this->state = $state;
        $this->errorMessage = $errorMessage;
    }

    public function getState(): string 
    { 
        return $this->state; 
    }

    public function getRepositoryName(): string 
    { 
        return $this->repositoryName; 
    }

    public function getPluginFile(): ?string 
    { 
        return $this->pluginFile; 
    }

    public function setPluginFile(?string $pluginFile): void 
    { 
        $this->pluginFile = $pluginFile; 
    }

    public function getSettingsUrl(): ?string 
    { 
        return $this->settingsUrl; 
    }

    public function setSettingsUrl(?string $settingsUrl): void 
    { 
        $this->settingsUrl = $settingsUrl; 
    }

    public function getErrorMessage(): ?string 
    { 
        return $this->errorMessage; 
    }

    public function getMetadata(): array 
    { 
        return $this->metadata; 
    }

    public function setMetadata(array $metadata): void 
    { 
        $this->metadata = $metadata; 
    }

    public function getVersion(): ?string 
    { 
        return $this->metadata['version'] ?? null; 
    }

    public function getDescription(): ?string 
    { 
        return $this->metadata['description'] ?? null; 
    }

    public function getName(): ?string 
    { 
        return $this->metadata['name'] ?? null; 
    }
}
