from pydantic import BaseModel
from typing import Optional, List

class MemoryItem(BaseModel):
    namespace: str = "default"
    content: str
    metadata: Optional[dict] = {}

class MemoryUpdateItem(BaseModel):
    id: int
    content: str
    metadata: Optional[dict] = None  # None = keep existing, {} = clear, otherwise = replace

class SearchQuery(BaseModel):
    namespace: str = "default"
    query: str
    limit: int = 5

class SearchResult(BaseModel):
    id: int
    content: str
    score: float
    metadata: Optional[dict]

class AuditSearchQuery(BaseModel):
    query: str
    limit: int = 5

class AuditSearchResult(BaseModel):
    id: int
    memory_id: int
    content: str
    metadata: str
    timestamp: str
