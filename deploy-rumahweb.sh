#!/bin/bash
#
# Deploy Script for adf.system (Rumahweb - Safe Git Merge)
# Cara pakai: bash deploy-rumahweb.sh
#
# Script ini:
# 1. Initialize git di folder yang sudah ada (data aman)
# 2. Set remote ke GitHub
# 3. Pull file terbaru dari GitHub
# 4. Merge dengan hati-hati (warning jika ada conflict)
#

set -e  # Exit jika ada error

# ==========================================
# KONFIGURASI - EDIT INI SESUAI RUMAHWEB ANDA
# ==========================================
SSH_USER="adfb2574"                           # Username SSH Rumahweb (cPanel username)
SSH_HOST="ssh.rumahweb.com"                   # SSH Host Rumahweb
REMOTE_PATH="/home/adfb2574/public_html/narayanakarimunjawa/adf.system"  # Path folder di server
GITHUB_REPO="https://github.com/arifnarayana88-collab/adf.system.git"
GITHUB_BRANCH="main"

# ==========================================
# DEPLOY SCRIPT
# ==========================================

echo "=================================================="
echo "🚀 ADF System - Safe Deployment Script"
echo "=================================================="
echo ""
echo "📋 Configuration:"
echo "   SSH User: $SSH_USER"
echo "   SSH Host: $SSH_HOST"
echo "   Remote Path: $REMOTE_PATH"
echo "   GitHub Repo: $GITHUB_REPO"
echo ""

# Confirm sebelum deploy
read -p "Lanjut deploy? (y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "❌ Deploy dibatalkan"
    exit 1
fi

echo ""
echo "🔄 Menghubung ke server..."

# SSH dan jalankan script di server
ssh -t "$SSH_USER@$SSH_HOST" << 'REMOTE_SCRIPT'
set -e

REMOTE_PATH="$1"
GITHUB_REPO="$2"
GITHUB_BRANCH="$3"

echo "📂 Navigasi ke folder aplikasi..."
cd "$REMOTE_PATH" || { echo "❌ Folder tidak ditemukan: $REMOTE_PATH"; exit 1; }

echo ""
echo "🔍 Cek status folder..."
ls -la | head -10

echo ""
echo "✅ Step 1: Initialize Git Repository..."
if [ ! -d .git ]; then
    git init
    echo "   ✓ Git initialized"
else
    echo "   ✓ Git sudah ada"
fi

echo ""
echo "✅ Step 2: Set Remote ke GitHub..."
if git remote | grep -q origin; then
    echo "   ✓ Remote 'origin' sudah ada"
    git remote set-url origin "$GITHUB_REPO"
else
    git remote add origin "$GITHUB_REPO"
    echo "   ✓ Remote 'origin' ditambahkan"
fi

echo ""
echo "✅ Step 3: Fetch dari GitHub..."
git fetch origin "$GITHUB_BRANCH"
echo "   ✓ Fetch selesai"

echo ""
echo "✅ Step 4: Configure Git Identity (untuk merge)..."
git config user.email "deploy@system.local" || true
git config user.name "Deploy Bot" || true
echo "   ✓ Git identity configured"

echo ""
echo "✅ Step 5: Create/Set Branch Tracking..."
if git rev-parse --verify "$GITHUB_BRANCH" > /dev/null 2>&1; then
    echo "   ✓ Branch $GITHUB_BRANCH sudah ada"
else
    git branch "$GITHUB_BRANCH" "origin/$GITHUB_BRANCH"
    echo "   ✓ Branch $GITHUB_BRANCH dibuat"
fi

echo ""
echo "✅ Step 6: Checkout Branch..."
git checkout "$GITHUB_BRANCH"
echo "   ✓ Branch $GITHUB_BRANCH checkout"

echo ""
echo "✅ Step 7: Set Branch Upstream..."
git branch --set-upstream-to="origin/$GITHUB_BRANCH" "$GITHUB_BRANCH" || true
echo "   ✓ Upstream set"

echo ""
echo "✅ Step 8: Merge dengan GitHub (Careful Merge)..."
echo "   ⚠️  Jika ada CONFLICT, Anda harus resolve secara manual!"
echo ""

# Merge dengan --no-ff untuk history yang lebih jelas
git merge --no-ff "origin/$GITHUB_BRANCH" --message "Merge from GitHub - $(date)" || {
    echo ""
    echo "⚠️  MERGE CONFLICT TERDETEKSI!"
    echo "❌ Anda harus resolve conflict secara manual:"
    echo ""
    echo "   1. SSH ke server: ssh $SSH_USER@$SSH_HOST"
    echo "   2. cd $REMOTE_PATH"
    echo "   3. git status  (lihat file conflict)"
    echo "   4. Edit file conflict (hapus marker <<<, ===, >>>)"
    echo "   5. git add . && git commit -m 'Resolve conflict'"
    echo ""
    exit 1
}

echo ""
echo "=================================================="
echo "✅ DEPLOY SELESAI!"
echo "=================================================="
echo ""
echo "📂 Files di server:"
ls -la | head -20
echo ""
echo "📊 Git Status:"
git log --oneline -5
echo ""

REMOTE_SCRIPT "$REMOTE_PATH" "$GITHUB_REPO" "$GITHUB_BRANCH"

# Cek exit code
if [ $? -eq 0 ]; then
    echo ""
    echo "✅ ✅ ✅ DEPLOY BERHASIL!"
    echo ""
    echo "🎉 File terbaru sudah tersedia di server:"
    echo "   - developer/debug-business-status.php"
    echo "   - reset-dev-password.php"
    echo ""
else
    echo ""
    echo "❌ DEPLOY GAGAL - Ada error atau conflict"
    echo "Silakan resolve secara manual dan coba lagi"
    exit 1
fi
