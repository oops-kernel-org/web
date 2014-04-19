#!/bin/env sh
find . -type f -name '*.zip' | xargs sha1sum > sha1sum;
