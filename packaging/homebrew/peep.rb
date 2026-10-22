# Homebrew cask for the Peep desktop app (RL-10), for a tap such as shibbirweb/homebrew-tap:
#     brew install --cask shibbirweb/tap/peep
# On each release, set "version" and the two sha256 values from the release's .dmg files.
cask "peep" do
  arch arm: "aarch64", intel: "x64"

  version "1.0.0"
  sha256 arm:   "REPLACE_WITH_SHA256_OF_Peep_1.0.0_aarch64.dmg",
         intel: "REPLACE_WITH_SHA256_OF_Peep_1.0.0_x64.dmg"

  url "https://github.com/shibbirweb/peep/releases/download/v#{version}/Peep_#{version}_#{arch}.dmg"
  name "Peep"
  desc "Debug any PHP app in a separate window"
  homepage "https://github.com/shibbirweb/peep"

  livecheck do
    url :url
    strategy :github_latest
  end

  depends_on macos: ">= :big_sur"

  app "Peep.app"

  zap trash: [
    "~/.config/peep",
    "~/Library/Application Support/com.shibbirweb.peep",
    "~/Library/Caches/com.shibbirweb.peep",
  ]
end
