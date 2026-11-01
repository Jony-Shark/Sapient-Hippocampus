<?php

namespace Sapient\Hippocampus;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Contracts\Core\ContextProviderInterface;
use Sapient\Hippocampus\Config\HippocampusConfig;

class HippocampusClient implements ContextProviderInterface {
    private $baseUrl;
    private $client;
    private $runner;
    private $config;

    public function __construct(HippocampusConfig $config) {
        $this->config = $config;
        $this->baseUrl = $config->getUrl();
        $this->client = new Client(['timeout' => 2.0]);

        if ($config->getAutoStart() && !$this->isAvailable()) {
            $parsedUrl = parse_url($this->baseUrl);
            $host = $parsedUrl['host'] ?? '127.0.0.1';
            $port = $parsedUrl['port'] ?? 6366;
            
            $this->runner = new HippocampusRunner();
            
            error_log("Hippocampus is offline. Auto-starting Python server on {$host}:{$port}...");
            if ($this->runner->start($host, $port)) {
                // Poll for up to 30 seconds (Model loading can be slow)
                for ($i = 1; $i <= 30; $i++) {
                    sleep(1);
                    if ($this->isAvailable()) {
                        error_log("Hippocampus server is now ONLINE (detected after {$i}s).");
                        break;
                    }
                    if ($i % 5 === 0) {
                        error_log("Hippocampus still loading... (waited {$i}s)");
                    }
                }
                
                if (!$this->isAvailable()) {
                    error_log("Hippocampus server failed to come online within 30s. Background process might still be starting.");
                }
            } else {
                error_log("Failed to start Hippocampus server.");
            }
        }
    }

    /**
     * Stores a memory in the vector store.
     * 
     * @param string $content The text content to memorize.
     * @param array $metadata Optional metadata (e.g., source, timestamp).
     * @return array Response from server ('status', 'id', 'total').
     */
    public function remember(string $content, array $metadata = []): array {
        try {
            $namespace = $this->config->getPersona();
            $response = $this->client->post("{$this->baseUrl}/remember", [
                'json' => [
                    'namespace' => $namespace,
                    'content' => $content,
                    'metadata' => $metadata
                ]
            ]);
            
            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            // Failure is an option (e.g. server down), return error structure
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Updates an existing memory in the SQLite database and logs the audit.
     * 
     * @param int $id The ID of the memory to update.
     * @param string $content The new text content (e.g. "[Törölve]").
     * @return array Response from server ('status', 'id').
     */
    public function updateMemory(int $id, string $content, array $metadata = []): array {
        try {
            $payload = [
                'id'          => $id,
                'content' => $content,
                // null → server keeps existing metadata; a dict → server replaces it
                'metadata' => empty($metadata) ? null : $metadata,
            ];
            $response = $this->client->post("{$this->baseUrl}/update", [
                'json' => $payload
            ]);
            
            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Recalls memories relevant to the query.
     * 
     * @param string $query The search query.
     * @param int $limit Max number of results.
     * @return array List of results. Each result has 'content', 'score', 'metadata'.
     */
    public function recall(string $query, int $limit = 5): array {
        try {
            $namespace = $this->config->getPersona();
            $response = $this->client->post("{$this->baseUrl}/recall", [
                'json' => [
                    'namespace' => $namespace,
                    'query' => $query,
                    'limit' => $limit
                ]
            ]);
            
            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            return [];
        }
    }

    /**
     * Recalls redacted/deleted memories from the audit log.
     * 
     * @param string $query The search query or specific memory ID.
     * @param int $limit Max number of results.
     * @return array List of audit results.
     */
    public function recallAudit(string $query, int $limit = 5): array {
        try {
            $response = $this->client->post("{$this->baseUrl}/recall_audit", [
                'json' => [
                    'query' => $query,
                    'limit' => $limit
                ]
            ]);
            
            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            return [];
        }
    }

    // --- ContextProviderInterface ---

    public function getContextName(): string {
        return 'long_term_memory';
    }

    public function provideContext(StateInterface $state): ?string {
        $input = $state->get('input');
        if (empty($input) || !is_string($input)) {
            return null;
        }

        // Only recall if input is meaningful
        $memories = $this->recall($input, $this->config->getContextLimit());
        
        $output = "";
        $count = 0;
        foreach ($memories as $mem) {
            $score = $mem['score'] ?? 100; // Default to high distance (bad)
            
            // Filter low confidence (assuming L2 Distance: lower is better)
            // Threshold 1.25 roughly equals Cosine Similarity ~0.2. 
            // Threshold 1.0 roughly equals Cosine Similarity ~0.5.
            if ($score > 1.2) continue;
            
            $id = $mem['id'] ?? 'unknown';
            $content = $mem['content'] ?? '';
            $score = number_format($score, 2);
            $meta = $mem['metadata'] ?? [];
            
            $metaStrs = [];
            if (!empty($meta['emotion'])) $metaStrs[] = "emotion: {$meta['emotion']}";
            if (!empty($meta['confidence'])) $metaStrs[] = "confidence: {$meta['confidence']}";
            if (!empty($meta['intensity'])) $metaStrs[] = "intensity: {$meta['intensity']}";
            if (!empty($meta['image_path'])) $metaStrs[] = "image_path: {$meta['image_path']}";
            
            $metaString = !empty($metaStrs) ? " {" . implode(", ", $metaStrs) . "}" : "";
            
            $output .= "- [ID: $id] (dist: $score) $content$metaString\n";
            $count++;
        }
        
        if ($count === 0) return null;
        
        return "[RELEVANT MEMORIES (Hippocampus)]\n" . $output;
    }
    
    /**
     * Checks if the memory service is available.
     */
    public function isAvailable(): bool {
        try {
            $response = $this->client->get("{$this->baseUrl}/health");
            $data = json_decode($response->getBody(), true);
            return ($data['status'] ?? '') === 'ok';
        } catch (GuzzleException $e) {
            return false;
        }
    }

    /**
     * Gracefully shuts down the background python server if we started it.
     */
    public function shutdown(): void {
        if ($this->runner) {
            $this->runner->stop();
            error_log("Hippocampus auto-started server has been shut down.");
        }
    }
}
