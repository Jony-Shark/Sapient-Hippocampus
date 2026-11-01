"""
Hippocampus Memory Cleanup & Index Rebuilder
============================================

This script allows for manual management of the AI's long-term memory.
You can list, delete, and rebuild vector indices for specific personas.

Usage:
------
1. Ensure you are using the virtual environment:
   $ cd hippocampus
   $ ./venv/bin/python clean_memories.py [options]

Options:
--------
   --persona <name>  Focus on a specific persona (e.g., lyra, mira).
   --all             List all memories across all personas.

Notes:
------
- Ideally, STOP the Hippocampus server before running this script to avoid
  SQLite 'database is locked' errors during deletion or index rebuilding.
- Deleting an entry here is permanent but is preserved in the 'memory_audit' table.
- Use the [r] option in the menu after deleting to sync the FAISS index.
"""

import sqlite3
import faiss
from sentence_transformers import SentenceTransformer
import numpy as np
import os
import json
import argparse
import sys

# Configuration
DB_FILE = "database/hippocampus.db"
SYNAPSES_DIR = "synapses"
MODEL_NAME = "all-MiniLM-L6-v2"
DIM = 384

def connect_db():
    return sqlite3.connect(DB_FILE)

def list_memories(conn, persona=None):
    c = conn.cursor()
    if persona:
        # We look into the metadata JSON string for the 'persona' key
        # SQLite's JSON support: json_extract(metadata, '$.persona')
        c.execute("SELECT id, content, timestamp, metadata FROM memories WHERE json_extract(metadata, '$.persona') = ? ORDER BY id ASC", (persona,))
    else:
        c.execute("SELECT id, content, timestamp, metadata FROM memories ORDER BY id ASC")
        
    rows = c.fetchall()
    
    if not rows:
        print(f"\n[INFO] No memories found for persona: {persona if persona else 'ALL'}")
        return []
        
    print(f"\n[INFO] Found {len(rows)} memories:")
    print("-" * 100)
    print(f"{'ID':<5} | {'Persona':<12} | {'Timestamp':<20} | {'Content (Truncated)':<65}")
    print("-" * 100)
    
    for row in rows:
        mid, content, ts, meta_json = row
        meta = json.loads(meta_json) if meta_json else {}
        p = meta.get('persona', 'unknown')
        
        # Truncate content for display
        display_content = (content[:62] + '...') if len(content) > 65 else content
        display_content = display_content.replace('\n', ' ')
        print(f"{mid:<5} | {p:<12} | {ts:<20} | {display_content}")
    print("-" * 115)
    return rows

def delete_memories(conn, ids_to_delete):
    if not ids_to_delete:
        return 0
        
    c = conn.cursor()
    
    # Optional: Log to audit before deleting? 
    # Actually, the server's update_memory already does audit logging.
    # Manual cleanup via this script might want to skip audit or do it manually.
    # We'll just delete from main table.
    
    placeholders = ','.join('?' * len(ids_to_delete))
    sql = f"DELETE FROM memories WHERE id IN ({placeholders})"
    c.execute(sql, ids_to_delete)
    deleted_count = c.rowcount
    
    conn.commit()
    print(f"[SUCCESS] Deleted {deleted_count} rows from database.")
    return deleted_count

def rebuild_index(conn, persona):
    if not persona:
        print("[ERROR] Must specify a persona to rebuild a specific index.")
        return

    index_file = os.path.join(SYNAPSES_DIR, f"{persona}.index")
    print(f"\n[INFO] Rebuilding FAISS Index for: {persona}...")
    
    # Fetch all content for this persona only
    c = conn.cursor()
    c.execute("SELECT id, content FROM memories WHERE json_extract(metadata, '$.persona') = ? ORDER BY id ASC", (persona,))
    rows = c.fetchall()
    
    if not rows:
        print(f"[WARN] No memories found for {persona}. Removing index file.")
        if os.path.exists(index_file):
            os.remove(index_file)
        return

    # Load Model
    print(f"[INFO] Loading Model: {MODEL_NAME}...")
    model = SentenceTransformer(MODEL_NAME)
    
    # Encode
    ids = [r[0] for r in rows]
    contents = [r[1] for r in rows]
    
    print(f"[INFO] Encoding {len(contents)} items...")
    embeddings = model.encode(contents)
    
    # Create Index with IDMap
    # We use IndexIDMap to preserve the SQL IDs exactly as they are in the database.
    base_index = faiss.IndexFlatL2(DIM)
    index = faiss.IndexIDMap(base_index)
    
    # Add with explicit IDs
    id_array = np.array(ids).astype('int64')
    index.add_with_ids(embeddings, id_array)
    
    # Ensure directory exists
    if not os.path.exists(SYNAPSES_DIR):
        os.makedirs(SYNAPSES_DIR)
        
    # Save
    faiss.write_index(index, index_file)
    print(f"[SUCCESS] Index {index_file} rebuilt with {index.ntotal} vectors.")

def main():
    parser = argparse.ArgumentParser(description="Hippocampus Memory Cleanup Tool")
    parser.add_argument("--persona", help="Specific persona to manage (e.g. lyra, mira)")
    parser.add_argument("--all", action="store_true", help="List all memories across all personas")
    args = parser.parse_args()

    if not os.path.exists(DB_FILE):
        print(f"[ERROR] Database file {DB_FILE} not found. Please check paths.")
        sys.exit(1)

    conn = connect_db()
    
    target_persona = args.persona
    
    while True:
        list_memories(conn, target_persona)
        
        print("\nOptions:")
        print(" [d] Delete specific IDs (e.g. '1, 2, 5')")
        print(" [r] Rebuild Index (for current persona)")
        print(" [p] Switch Persona")
        print(" [q] Quit")
        
        choice = input("\nSelect option: ").strip().lower()
        
        if choice == 'q':
            break
            
        elif choice == 'p':
            target_persona = input("Enter persona name (or leave empty for ALL): ").strip()
            if not target_persona: target_persona = None
            
        elif choice == 'd':
            ids_str = input("Enter IDs to delete (comma separated): ")
            try:
                ids = [int(x.strip()) for x in ids_str.split(',') if x.strip()]
                if not ids: continue
                
                confirm = input(f"Are you sure you want to delete {len(ids)} memories? (y/n): ")
                if confirm.lower() == 'y':
                    delete_memories(conn, ids)
                    # We should probably rebuild the index of the affected persona(s)
                    # For simplicity, we ask or just rebuild the current one
                    if target_persona:
                        rebuild_index(conn, target_persona)
                    else:
                        print("[INFO] Multiple personas might be affected. Please rebuild indices manually via 'r' for specific personas.")
            except ValueError:
                print("[ERROR] Invalid input. Please enter numbers.")
                
        elif choice == 'r':
            if not target_persona:
                p = input("Enter persona name to rebuild index for: ").strip()
                if p: rebuild_index(conn, p)
            else:
                rebuild_index(conn, target_persona)
            
    conn.close()

if __name__ == "__main__":
    main()
