$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$target = Join-Path $root 'python\f1-race-replay'
if (Test-Path (Join-Path $target '.git')) {
    git -C $target pull --ff-only
} elseif (Test-Path $target) {
    throw "Le dossier python\f1-race-replay existe déjà mais n'est pas un dépôt Git. Supprime-le ou déplace-le, puis relance le script."
} else {
    git clone https://github.com/IAmTomShaw/f1-race-replay.git $target
}
Write-Host "f1-race-replay est installé dans $target"
Write-Host "Aucun fichier du dépôt n'a été modifié par ce script."
