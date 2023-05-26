#!/bin/bash
scriptPath=$(dirname -- "$(readlink -f -- "$BASH_SOURCE")")
changelogPath=$scriptPath/../CHANGELOG.md
congiPath=$scriptPath/../Gateway/Config/Config.php
lastVersion=$(sed -n 's/## \[\([0-9]\+.[0-9]\+.[0-9]\+\)\].*/\1/p' $changelogPath | head -n1)

sed -i "s/return '[0-9]\+.[0-9]\+.[0-9]\+'/return '$lastVersion'/g" $congiPath
