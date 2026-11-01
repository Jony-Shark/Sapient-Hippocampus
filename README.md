# 🧠 Hippocampus - Long-Term Memory Module

**Hippocampus** is a high-performance vector-based and relational memory storage service. While architected as a standalone REST API service, it is primarily designed as the long-term context and "memory" engine for the **Sapient AI Framework**.

## 🚀 Key Features

*   **Semantic Search (Vector Store):** Uses the FAISS library and the `all-MiniLM-L6-v2` transformer model to enable search by meaning rather than just keywords.
*   **Structured Metadata:** Every memory entry supports arbitrary metadata (source, timestamp, type, image paths), allowing for rich context preservation.
*   **Multi-Persona (Namespacing):** Supports isolated namespaces, ensuring that different AI characters (e.g., Mira, Lyra) maintain distinct and unmixed memory pools.
*   **Memory Audit & History:** All memory updates are tracked. The system preserves historical states, allowing for the auditing of AI "learning" and the correction of false information.
*   **Hybrid Storage Architecture:** 
    *   **FAISS:** For lightning-fast vector similarity searches.
    *   **SQLite3:** For reliable storage of raw text content and metadata audit logs.
*   **Multimodal Readiness:** Prepared for image-path association alongside text memories for vision-augmented recall.

## 🛠 Technical Stack

The module is built on Python with the following core components:
- **FastAPI / Uvicorn:** High-speed asynchronous REST API.
- **Sentence-Transformers:** For text-to-vector embedding.
- **FAISS:** For efficient high-dimensional vector indexing.
- **Safe-Cleanup Tools:** Built-in utilities for deduplication and "forgetting" obsolete memories.

## 🔌 Integration with Sapient

In the Sapient ecosystem, Hippocampus acts as an optional plugin. The Sapient client handles automatic server startup if it is not already running and manages namespaces based on the `.env` `PERSONA` configuration.

**Manual Startup:**
```bash
./venv/bin/uvicorn server:app --host 127.0.0.1 --port 6366
```

---
*Part of the Sapient AI Framework ecosystem.*
