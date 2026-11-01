<?php

namespace Sapient\Hippocampus\Tools;

use Sapient\Contracts\Tools\ToolInterface;
use Sapient\Hippocampus\HippocampusClient;

class RememberTool implements ToolInterface {
    private $client;

    public function __construct(HippocampusClient $client) {
        $this->client = $client;
    }

    public function getName(): string {
        return 'remember';
    }

    public function getDescription(): string {
        return 'Read data from the Hippocampus.
        **Tool name:** `remember`
        **Function:** Read data from the Long-Term Memory or recalls a visual anchor. 
        
        **Parameters:**
        *   `type` (require): The type of operation. Possible values: `memory`, `visual`, `deep`.
        
        **Note:**
        The type="visual" for recalling an image using its {image_path} metadata
        If explicitly requested by the user to look up a censored/deleted memory log, use type="deep" with the memory ID as query.

        **Usage examples (syntax):**
        <remember type="memory">A very apt depiction.</remember>
        <remember type="visual">20250205_121221_69a48d6fc5f84.png</remember>';
    }

    public function getInstruction(): string {
        return 'Pattern: <remember type="memory|visual">{query}</remember>, Instruction: Retrieve information from memory based on a query or filename. Type defaults to "memory".';
    }

    public function execute(array $args): string {
        $type = $args['type'] ?? 'memory';
        $query = $args['content'] ?? $args['query'] ?? '';

        if (empty($query)) {
            return "Error: You must provide a query to remember.";
        }

        if ($type === 'visual') {
            return $this->handleVisual($query);
        } elseif ($type === 'deep') {
            return $this->handleDeep($query, $args);
        } else {
            return $this->handleMemory($query, $args);
        }
    }

    private function handleMemory(string $query, array $args): string {
        $limit = isset($args['limit']) ? (int)$args['limit'] : 5;
        $results = $this->client->recall($query, $limit);

        if (empty($results)) {
            return "No relevant memories found for query: '{$query}'";
        }

        $output = "Memories found for '{$query}':\n";
        foreach ($results as $mem) {
            $id = $mem['id'] ?? 'unknown';
            $text = $mem['content'] ?? '';
            $score = isset($mem['score']) ? number_format($mem['score'], 2) : '?';

            $meta = $mem['metadata'] ?? [];
            $metaStrs = [];
            if (!empty($meta['emotion'])) $metaStrs[] = "emotion: {$meta['emotion']}";
            if (!empty($meta['confidence'])) $metaStrs[] = "confidence: {$meta['confidence']}";
            if (!empty($meta['image_path'])) $metaStrs[] = "image_path: {$meta['image_path']}";
            $metaString = !empty($metaStrs) ? " {" . implode(", ", $metaStrs) . "}" : "";

            $output .= "- [ID: $id] (dist: $score) $text$metaString\n";
        }
        return $output;
    }

    private function handleDeep(string $query, array $args): string {
        $limit = isset($args['limit']) ? (int)$args['limit'] : 5;
        $results = $this->client->recallAudit($query, $limit);

        if (empty($results)) {
            return "No audit logs found for memory ID: '{$query}'";
        }

        $output = "Audit Recovered Memories found for ID #{$query}:\n";
        foreach ($results as $audit) {
            $memId = $audit['memory_id'] ?? 'unknown';
            $recoveredText = $audit['content'] ?? '';
            $recoveredMeta = $audit['metadata'] ?? '';
            $time = $audit['timestamp'] ?? '';

            $output .= "[MEMORY ID: {$memId} | TIME: {$time}]\n";
            $output .= "RECOVERED CONTENT: {$recoveredText}\n";
            $output .= "RECOVERED METADATA: {$recoveredMeta}\n\n";
        }
        return $output;
    }

    private function handleVisual(string $filename): string {
        $filename = trim($filename);
        $filename = basename($filename); // secure traversal
        
        $visualsDir = __DIR__ . '/../../visuals';
        $fullPath = $visualsDir . '/' . $filename;
        
        if (!file_exists($fullPath)) {
            return "Error: Visual memory not found: {$filename}";
        }
        
        $realPath = realpath($fullPath);
        
        // This token will be picked up by VertexAIAdapter and converted into a multimodal Base64 block
        return "Image retrieved successfully. Prepare to process visual data: [LOCAL_IMAGE:{$realPath}]";
    }
}
