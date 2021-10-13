#!/usr/bin/env bash
set -e

if [ "$1" == "--keep-alive" ]; then
  shift
  while true; do
    echo "run '${@}'"
    "${@}"
  done
else
  echo "run '${@}'"
  exec "${@}"
fi
