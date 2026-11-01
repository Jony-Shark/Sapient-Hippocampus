import sqlite3
import json
from typing import Optional, Tuple

class Database:
    def __init__(self, db_file: str):
        self.conn = sqlite3.connect(db_file, check_same_thread=False)
        self.init_db()

    def init_db(self):
        c = self.conn.cursor()
        c.execute('''CREATE TABLE IF NOT EXISTS memories
                     (id INTEGER PRIMARY KEY AUTOINCREMENT, 
                      content TEXT, 
                      metadata TEXT, 
                      timestamp DATETIME DEFAULT CURRENT_TIMESTAMP)''')
                      
        c.execute('''CREATE TABLE IF NOT EXISTS memory_audit
                     (id INTEGER PRIMARY KEY AUTOINCREMENT, 
                      memory_id INTEGER,
                      content TEXT,
                      metadata TEXT,
                      timestamp DATETIME DEFAULT CURRENT_TIMESTAMP)''')
        self.conn.commit()

    def add_memory(self, content: str, metadata: dict) -> int:
        c = self.conn.cursor()
        c.execute("INSERT INTO memories (content, metadata) VALUES (?, ?)", 
                  (content, json.dumps(metadata)))
        mem_id = c.lastrowid
        self.conn.commit()
        return mem_id

    def get_memory(self, memory_id: int) -> Optional[Tuple[str, dict]]:
        c = self.conn.cursor()
        c.execute("SELECT content, metadata FROM memories WHERE id=?", (memory_id,))
        row = c.fetchone()
        if row:
            content, meta_json = row
            return content, (json.loads(meta_json) if meta_json else {})
        return None

    def update_memory(self, memory_id: int, content: str, metadata: dict = None) -> bool:
        c = self.conn.cursor()
        c.execute("SELECT content, metadata FROM memories WHERE id=?", (memory_id,))
        row = c.fetchone()
        
        if not row:
            return False
            
        old_content, old_meta_json = row
        old_meta = json.loads(old_meta_json) if old_meta_json else {}
        
        # Insert previous state into audit
        c.execute("INSERT INTO memory_audit (memory_id, content, metadata) VALUES (?, ?, ?)",
                  (memory_id, old_content, old_meta_json))
        
        # Determine what metadata to write:
        # Merge metadata into old_meta instead of full overwrite
        if metadata is None:
            meta_to_write = old_meta
        else:
            meta_to_write = old_meta.copy() if old_meta else {}
            meta_to_write.update(metadata)
            
            # If the action is a deletion/forget, strip the image path
            if meta_to_write.get('action') == 'forgotten':
                meta_to_write.pop('image_path', None)
                
        meta_json_str = json.dumps(meta_to_write)

        c.execute("UPDATE memories SET content=?, metadata=? WHERE id=?", (content, meta_json_str, memory_id))
        self.conn.commit()
        return True

    def search_audit(self, query: str, limit: int = 5) -> list:
        c = self.conn.cursor()
        
        try:
            mem_id = int(query)
            c.execute("""SELECT id, memory_id, content, metadata, timestamp 
                         FROM memory_audit 
                         WHERE memory_id = ? 
                         ORDER BY timestamp DESC LIMIT ?""", (mem_id, limit))
            return c.fetchall()
        except ValueError:
            # If not an ID, return empty
            return []
