#!/bin/bash
# VSN Marketing - Automated Worker Commit Script
# Usage: ./github-auto-commit.sh <worker-id> <task-id> <message>

WORKER_ID=$1
TASK_ID=$2
MESSAGE=$3
BRANCH_NAME="worker-${WORKER_ID}/${TASK_ID}"

if [ -z "$WORKER_ID" ] || [ -z "$TASK_ID" ] || [ -z "$MESSAGE" ]; then
    echo "Usage: $0 <worker-id> <task-id> <commit-message>"
    exit 1
fi

echo "[Worker-${WORKER_ID}] Starting automated commit for ${TASK_ID}..."

# Create and checkout worker branch
git checkout -b "$BRANCH_NAME" 2>/dev/null || git checkout "$BRANCH_NAME"

# Stage all changes
git add -A

# Check if there are changes to commit
if git diff --staged --quiet; then
    echo "[Worker-${WORKER_ID}] No changes to commit."
    git checkout main
    exit 0
fi

# Commit with standardized message
git commit -m "chore(worker-${WORKER_ID}): ${MESSAGE} [${TASK_ID}]"

# Push to remote
git push -u origin "$BRANCH_NAME" --force

echo "[Worker-${WORKER_ID}] Successfully pushed to ${BRANCH_NAME}"
echo "[Worker-${WORKER_ID}] Branch URL: https://github.com/Vertex-Systems-Network/vsn-marketing/tree/${BRANCH_NAME}"

# Return to main
git checkout main
