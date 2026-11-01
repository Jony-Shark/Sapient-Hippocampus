from fastapi import FastAPI, HTTPException
import uvicorn
from typing import List

from models import MemoryItem, MemoryUpdateItem, SearchQuery, SearchResult, AuditSearchQuery, AuditSearchResult
from database import Database
from vector_store import VectorStoreManager

# ... [rest of the imports and setup remain the same] ...

app = FastAPI(title="Hippocampus", version="3.0.0")

# --- Configuration ---
params = {
    "model_name": "all-MiniLM-L6-v2",
    "db_file": "database/hippocampus.db",
    "dim": 384,
    "data_dir": "synapses"
}

# --- Initialize Modules ---
db = Database(params['db_file'])
vs = VectorStoreManager(params['model_name'], params['dim'], params['data_dir'])

# --- Routes ---
@app.get("/health")
def health_check():
    # Simplistic health check, total vectors across all namespaces is hard to aggregate quickly, 
    # we'll return default namespace count for backwards compatibility.
    return {"status": "ok", "service": "hippocampus", "memories_default": vs.get_total_vectors("default")}

@app.post("/remember")
def remember(item: MemoryItem):
    # 1. Store in Central DB (to get ID)
    # We can inject namespace into metadata if it's not there
    if not isinstance(item.metadata, dict):
        item.metadata = {}
    item.metadata['persona'] = item.namespace
    
    mem_id = db.add_memory(item.content, item.metadata)
    
    # 2. Add to Vector Index with explicit SQL ID
    vs.add_text_with_id(item.namespace, item.content, mem_id)
    
    return {"status": "stored", "id": mem_id, "total": vs.get_total_vectors(item.namespace)}

@app.post("/recall", response_model=List[SearchResult])
def recall(query: SearchQuery):
    # Search specific Vector Store
    D, I = vs.search(query.namespace, query.query, query.limit)
    
    results = []
    # Retrieve content from Shared DB for the matched vectors
    for j, db_id in enumerate(I[0]):
        # FAISS returns -1 if there aren't enough vectors in the index
        if db_id == -1: continue 
        
        # We explicitly stored the SQL ID in FAISS, so we just use db_id directly!
        res = db.get_memory(int(db_id))
        
        if res:
            content, metadata = res
            results.append(SearchResult(
                id=int(db_id),
                content=content,
                score=float(D[0][j]),
                metadata=metadata
            ))
            
    return results

@app.post("/update")
def update_memory(item: MemoryUpdateItem):
    # Note: We do NOT need the namespace here, because the SQL ID is globally unique!
    success = db.update_memory(item.id, item.content, item.metadata)
    
    if not success:
        raise HTTPException(status_code=404, detail="Memory not found in database")
            
    return {"status": "updated", "id": item.id}

@app.post("/recall_audit", response_model=List[AuditSearchResult])
def recall_audit(query: AuditSearchQuery):
    rows = db.search_audit(query.query, query.limit)
    
    results = []
    for row in rows:
        results.append(AuditSearchResult(
            id=row[0],
            memory_id=row[1],
            content=row[2] or "",
            metadata=row[3] or "",
            timestamp=row[4] or ""
        ))
        
    return results

if __name__ == "__main__":
    uvicorn.run("server:app", host="127.0.0.1", port=6366, reload=False)
