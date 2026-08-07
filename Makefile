.DEFAULT_GOAL := noop

include make/ship.mk
include make/docker.mk
include make/test.mk

.PHONY: noop
noop:
	@:
