# Local OrbStack Docker SSOT hygiene for Coolify dev.
# Keep-set: compose projects coolify + coolify-proxy, one multiarch builder.
# Lab residue (cpbg-*, production-application-*, extra buildx builders) is removed by clean.

.PHONY: docker-ssot-status docker-ssot-clean docker-ssot-ensure-builder

DOCKER_PUSH_BUILDER ?= aventure-runtime-multiarch-proxy
export DOCKER_PUSH_BUILDER

docker-ssot-status: ## Print SSOT keep-set vs live Docker inventory
	@scripts/dev/docker-ssot-clean.sh status

docker-ssot-ensure-builder: ## Ensure multiarch push builder (aventure-runtime-multiarch-proxy)
	@scripts/dev/docker-ssot-clean.sh ensure-builder

docker-ssot-clean: ## Tear down lab stacks; keep Coolify control plane containers/volumes
	@scripts/dev/docker-ssot-clean.sh clean
