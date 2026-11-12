# Buildx bake for Signaculum (linux/amd64 + linux/arm64).
# Publishing to GHCR happens in the release workflow, not here.
#
#   docker buildx bake -f docker-bake.hcl production

variable "REGISTRY" {
  default = "ghcr.io/qiick-io/signaculum-core"
}

variable "VERSION" {
  default = "dev"
}

group "default" {
  targets = ["production"]
}

target "production" {
  context    = "."
  dockerfile = "Dockerfile"
  target     = "production"
  platforms  = ["linux/amd64", "linux/arm64"]
  tags = [
    "signaculum:prod",
    "${REGISTRY}:${VERSION}",
  ]
}
