#!/usr/bin/env bash
set -e
trap 'echo "SIGTERM signal caught, exiting"; kill -15 $PID; wait $PID' SIGTERM

while true; do
  "${@}" &
  PID=$!
  wait $PID
done
