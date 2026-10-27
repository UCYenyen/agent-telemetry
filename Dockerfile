FROM python:3.12-slim

# Install system dependencies (tmux, curl, procps, git)
RUN apt-get update && apt-get install -y --no-install-recommends \
    tmux \
    curl \
    git \
    procps \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Install Graphify knowledge graph engine for AST parsing and token reduction
RUN pip install --no-cache-dir graphifyy

WORKDIR /app

# Copy application files
COPY . /app

EXPOSE 3116

ENV PYTHONUNBUFFERED=1

CMD ["python3", "server.py"]
