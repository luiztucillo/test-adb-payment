#!/bin/bash

scriptPath=$(dirname -- "$(readlink -f -- "$BASH_SOURCE")")

changelogPath=$scriptPath/../CHANGELOG.md
congiPath=$scriptPath/../Gateway/Config/Config.php

lastChangelogVersion=$(sed -n 's/## \[\([0-9]\+.[0-9]\+.[0-9]\+\)\].*/\1/p' $changelogPath | head -n1 | xargs)
configVersion=$(sed -n "s/return '\([0-9]\+.[0-9]\+.[0-9]\+\)';/\1/p" $congiPath | head -n1 | xargs)

if [ "$lastChangelogVersion" = "$configVersion" ]; then
    echo "OK"
    echo "LAST CHANGELOG VERSION: '$lastChangelogVersion'"
    echo "CONFIG VERSION: '$configVersion'"
    exit 0
else
    echo "LAST CHANGELOG VERSION: '$lastChangelogVersion' not equal to CONFIG VERSION: '$configVersion'"
    exit 1
fi