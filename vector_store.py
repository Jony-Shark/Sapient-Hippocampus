import faiss
from sentence_transformers import SentenceTransformer
import numpy as np
import os
from typing import Tuple, Dict

class VectorStoreManager:
    def __init__(self, model_name: str, dim: int, data_dir: str = "./synapses"):
        self.dim = dim
        self.data_dir = data_dir
        self.indexes: Dict[str, faiss.IndexIDMap] = {}
        
        # Ensure directory exists
        if not os.path.exists(self.data_dir):
            os.makedirs(self.data_dir)
            
        print(f"Loading Model: {model_name}...")
        self.model = SentenceTransformer(model_name)
        print("Model Loaded.")
        
    def _get_index_path(self, namespace: str) -> str:
        return os.path.join(self.data_dir, f"{namespace}.index")
        
    def _get_index(self, namespace: str) -> faiss.IndexIDMap:
        if namespace not in self.indexes:
            idx_file = self._get_index_path(namespace)
            if os.path.exists(idx_file):
                print(f"Loading FAISS Index for namespace: {namespace}...")
                self.indexes[namespace] = faiss.read_index(idx_file)
            else:
                print(f"Creating new FAISS Index for namespace: {namespace}...")
                # Create an IDMap around a FlatL2 index to support explicit SQL IDs
                base_index = faiss.IndexFlatL2(self.dim)
                self.indexes[namespace] = faiss.IndexIDMap(base_index)
        return self.indexes[namespace]
        
    def encode(self, text: str) -> np.ndarray:
        return self.model.encode([text])

    def add_text_with_id(self, namespace: str, text: str, sql_id: int):
        vector = self.encode(text)
        idx = self._get_index(namespace)
        
        # FAISS requires IDs to be a 1D numpy array of type int64
        id_array = np.array([sql_id], dtype=np.int64)
        idx.add_with_ids(vector, id_array)
        
        # Save to disk
        faiss.write_index(idx, self._get_index_path(namespace))
        
    def search(self, namespace: str, query: str, limit: int = 5) -> Tuple[np.ndarray, np.ndarray]:
        idx = self._get_index(namespace)
        q_vector = self.encode(query)
        D, I = idx.search(q_vector, limit)
        return D, I
        
    def get_total_vectors(self, namespace: str) -> int:
        idx = self._get_index(namespace)
        return idx.ntotal

