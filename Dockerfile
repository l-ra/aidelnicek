# Final image: extends base (dependencies) with application code.
# Build with: docker build --build-arg BASE_IMAGE=ghcr.io/owner/repo-base:base-xxx -t app .
ARG BASE_IMAGE
FROM ${BASE_IMAGE}

# Copy application code (overlays on top of base's vendor/, etc.)
COPY --chown=www-data:www-data . /var/www/html/
WORKDIR /var/www/html

EXPOSE 80
