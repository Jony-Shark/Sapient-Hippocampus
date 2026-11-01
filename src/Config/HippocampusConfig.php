<?php

namespace Sapient\Hippocampus\Config;

use InvalidArgumentException;

/**
 * Configuration object for Hippocampus service.
 * 
 * Decoupled from global state/ENV, ensures all required parameters are present.
 */
class HippocampusConfig {
    private string $url;
    private string $persona;
    private int $contextLimit;
    private bool $autoStart;

    /**
     * @param string $url The base URL of the Hippocampus server (e.g. http://127.0.0.1:6366).
     * @param string $persona The namespace/persona for memory separation.
     * @param int $contextLimit Max number of memories to recall per turn.
     * @param bool $autoStart Whether to try starting the Python server if it is offline.
     * 
     * @throws InvalidArgumentException If required parameters are missing or invalid.
     */
    public function __construct(
        string $url, 
        string $persona = 'default', 
        int $contextLimit = 3, 
        bool $autoStart = true
    ) {
        if (empty($url)) {
            throw new InvalidArgumentException("Hippocampus URL is required.");
        }
        
        $this->url = rtrim($url, '/');
        $this->persona = $persona;
        $this->contextLimit = $contextLimit;
        $this->autoStart = $autoStart;
    }

    /**
     * Helper to create config from an associative array.
     */
    public static function fromArray(array $data): self {
        return new self(
            $data['url'] ?? '',
            $data['persona'] ?? 'default',
            $data['contextLimit'] ?? 3,
            $data['autoStart'] ?? true
        );
    }

    public function getUrl(): string {
        return $this->url;
    }

    public function getPersona(): string {
        return $this->persona;
    }

    public function getContextLimit(): int {
        return $this->contextLimit;
    }

    public function getAutoStart(): bool {
        return $this->autoStart;
    }
}
