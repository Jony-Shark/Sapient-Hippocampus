<?php

namespace Sapient\Hippocampus\Tools;

use Sapient\Contracts\Tools\ToolInterface;
use Sapient\Contracts\Core\StateInterface;
use Sapient\Hippocampus\HippocampusClient;

class MemorizeTool implements ToolInterface {
    private $client;

    public function __construct(HippocampusClient $client) {
        $this->client = $client;
    }

    public function getName(): string {
        return 'memorize';
    }

    public function getDescription(): string {
        return 'Stores content in the AI\'s long-term memory (Hippocampus).
        **Tool name:** `memorize`
        **Function:** It allows for the storage of new facts, events, and visual anchors, as well as the "updating" or "forgetting" of existing memories.

        **Parameters:**
        *   `action` (require): The type of operation. Possible values: `store`, `update`, `forget`.
        *   `emotion` (optional): The dominant emotion associated with the memory (e.g., "joy", "melancholy").
        *   `confidence` (optional): A measure of the accuracy/certainty of the memory, between 0.0 and 1.0..
        *   `image` (optional): The path to a local image file that is attached to the memory as a visual anchor. It is automatically internalized by the system.
        *   `id` (required by update|forget action): The unique identifier of the memory you want to modify or delete.

        **Usage examples (syntax):**
        <memorize action="store" emotion="fascination" confidence="1.0" image="/path/to/img.jpg">A very apt depiction.</memorize>
        <memorize action="update" id="123">A refined version of the previous memory.</memorize>
        <memorize action="forget" id="123">The reason why the memory had to be deleted (e.g. user requested it).</memorize>';
    }

    public function getInstruction(): string {
        return 'Pattern: <memorize action="store" emotion="..." confidence="0-1" image="{path}">{content}</memorize> OR <memorize action="update|forget" id="{memory_id}">{content}|{reason}</memorize>, Instruction: Manage Long-Term Memory (store or update facts).';
    }

    public function execute(array $args): string {
        if (!$this->client->isAvailable()) {
            return "Error: Memory service (Hippocampus) is offline.";
        }
        
        $action = $args['action'] ?? 'store';
        $content = $args['content'] ?? $args['value'] ?? null;

        if (!$content) {
            return "Error: No content provided.";
        }

        if ($action === 'forget') {
            $id = $args['id'] ?? null;
            $reason = '[Deleted] I had to forget.';
            
            if (!$id) {
                return "Error: Must provide an 'id' attribute when action='forget'.";
            }

            // Format the replaced content.
            if (empty(trim($content))) {
                $content = $reason;
            }

            $actionMeta = [
                'action'    => 'forgotten',
                'timestamp' => date('Y-m-d H:i:s'),
            ];
            
            $result = $this->client->updateMemory((int)$id, $content, $actionMeta);

            if (isset($result['status']) && $result['status'] === 'updated') {
                return "Updated ID:{$id}";
            } else {
                return "Error: " . ($result['message'] ?? 'Unknown');
            }
        } else if ($action === 'update') {
            $id = $args['id'] ?? null;
            
            if (!$id) {
                return "Error: Must provide an 'id' attribute when action='update'.";
            }

            $actionMeta = [
                'action'    => 'updated',
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            $result = $this->client->updateMemory((int)$id, $content, $actionMeta);
            
            if (isset($result['status']) && $result['status'] === 'updated') {
                return "Updated ID:{$id}";
            } else {
                return "Error: " . ($result['message'] ?? 'Unknown');
            }
        } else {
            // Default store behavior
            $metadata = [
                'source' => 'chat',
                'emotion' => $args['emotion'] ?? 'neutral',
                'confidence' => $args['confidence'] ?? '1.0',
                'intensity' => $args['intensity'] ?? '0.5',
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // Handle optional image attachment
            $imageSource = $args['image'] ?? null;
            if ($imageSource && file_exists($imageSource)) {
                $visualsDir = __DIR__ . '/../../visuals';
                if (!is_dir($visualsDir)) {
                    mkdir($visualsDir, 0777, true);
                }
                
                $ext = pathinfo($imageSource, PATHINFO_EXTENSION);
                if (empty($ext)) $ext = 'jpg'; // fallback
                
                // UUID / Timestamped filename to prevent overwrite
                $filename = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
                $targetPath = $visualsDir . '/' . $filename;
                
                if (copy($imageSource, $targetPath)) {
                    $metadata['image_path'] = $filename;
                }
            }

            $result = $this->client->remember($content, $metadata);
            
            if (isset($result['status']) && $result['status'] === 'stored') {
                return "Stored ID:{$result['id']}";
            } else {
                return "Error: " . ($result['message'] ?? 'Unknown');
            }
        }
    }
}
