#!/usr/bin/env bash
#
# Composer installs the archive GitHub builds from a tag, which honours the
# export-ignore rules in .gitattributes. This fails if that archive would contain
# anything besides the client package.

set -euo pipefail

allowed='^(src/|config/|resources/|phpstan/|bin/peep-clean$|composer\.json$|LICENSE$|README\.md$|CHANGELOG\.md$)'

unexpected=$(git archive --format=tar HEAD | tar -t | grep -v '/$' | grep -Ev "$allowed" || true)

if [ -n "$unexpected" ]; then
    echo "These files would be installed by 'composer require shibbirweb/peep' but are not part of the client:"
    echo "$unexpected"
    echo "Add them to .gitattributes with export-ignore, or to the allowed list in this script."
    exit 1
fi

echo "The archive contains only client files:"
git archive --format=tar HEAD | tar -t | grep -v '/$'
