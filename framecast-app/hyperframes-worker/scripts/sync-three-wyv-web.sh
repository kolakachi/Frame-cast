#!/bin/sh
# The web app's plan card draws the 3D mascot with the same bundle the builder uses. Rebuild the sandbox image after
# changing runtime/wyv-mascot3d.js, then run this from framecast-app/ to copy the bundle into web/public/vendor.
set -e
docker run --rm --entrypoint cat wyv-hyperframes-proof-smoke /opt/worker/runtime/three-wyv.js > web/public/vendor/three-wyv.js
echo "web/public/vendor/three-wyv.js updated ($(wc -c < web/public/vendor/three-wyv.js) bytes)"
