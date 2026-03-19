# Final image: extends base (dependencies) with application code.
# Build with: docker build --build-arg BASE_IMAGE=ghcr.io/owner/repo-base:base-xxx -t app .
ARG BASE_IMAGE
ARG GIT_SHA=dev
ARG BUILD_DATE=unknown
FROM ${BASE_IMAGE}

# Copy application code (overlays on top of base's vendor/, etc.)
COPY --chown=www-data:www-data . /var/www/html/
WORKDIR /var/www/html

# Generate version.json for footer display (git hash + build date)
RUN echo '{"version":"'"${GIT_SHA}"'","build_date":"'"${BUILD_DATE}"'"}' > /var/www/html/version.json

EXPOSE 80
