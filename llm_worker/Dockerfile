# Final image: extends base (dependencies) with application code.
# Build with: docker build --build-arg BASE_IMAGE=ghcr.io/owner/repo-llm-worker-base:base-xxx -t worker .
ARG BASE_IMAGE
FROM ${BASE_IMAGE}

COPY . .

EXPOSE 8001

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8001"]
