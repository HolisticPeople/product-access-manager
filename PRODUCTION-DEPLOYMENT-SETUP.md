# Production Deployment Setup Guide

**Current Status**: GitHub Actions workflow is configured and ready!

---

## How It Works

Your GitHub Actions workflow supports **two deployment modes**:

### 1. Automatic Deployment (Staging Only)
- Push to `dev` branch → Auto-deploys to **staging**
- ✅ Already configured and working

### 2. Manual Deployment (Staging OR Production)
- Go to GitHub Actions tab
- Click "Run workflow"
- Choose environment: `staging` or `production`
- Click "Run workflow" button
- ⚠️ Needs production secrets configured

---

## Setup Production Deployment

### Step 1: Get Production SSH Credentials

You need the SSH connection details for your **live production server** (holisticpeople.com).

From Kinsta dashboard:
1. Go to your **production** site (holisticpeople.com)
2. Click on "Info" tab
3. Note down:
   - **SSH Host**: (e.g., `123.456.789.012` or hostname)
   - **SSH Port**: (e.g., `12345`)
   - **SSH Username**: (e.g., `holisticpeople_123`)
   - **SSH Path to plugins**: (e.g., `/www/holisticpeople_123/public/wp-content/plugins`)

4. Get SSH Private Key:
   - Either from your existing SSH key
   - Or generate a new one in Kinsta dashboard

### Step 2: Add GitHub Secrets

Go to your GitHub repository:
1. Click **Settings** tab
2. Click **Secrets and variables** → **Actions**
3. Click **New repository secret**

Add these secrets:

#### For Production (NEW - Need to Add)

**Secret Name**: `KINSTAPROD_HOST`
- **Value**: Production SSH host (e.g., `123.456.789.012`)

**Secret Name**: `KINSTAPROD_PORT`  
- **Value**: Production SSH port (e.g., `12345`)

**Secret Name**: `KINSTAPROD_USER`
- **Value**: Production SSH username (e.g., `holisticpeople_123`)

**Secret Name**: `KINSTAPROD_SSH_KEY`
- **Value**: Production SSH private key (entire contents, including `-----BEGIN OPENSSH PRIVATE KEY-----` and `-----END OPENSSH PRIVATE KEY-----`)

**Secret Name**: `KINSTAPROD_PLUGINS_BASE`
- **Value**: Path to WordPress plugins directory (e.g., `/www/holisticpeople_123/public/wp-content/plugins`)

#### Existing Secrets (Already Configured)

These are for staging - already set up:
- ✅ `KINSTA_HOST`
- ✅ `KINSTA_PORT`
- ✅ `KINSTA_USER`
- ✅ `KINSTA_SSH_KEY`
- ✅ `KINSTA_PLUGINS_BASE`
- ✅ `PLUGIN_FOLDER_NAME` (should be `product-access-manager`)

### Step 3: Test Production Deployment

Once secrets are configured:

1. **Go to GitHub Actions**:
   - Visit: https://github.com/Amnonman/Product-Access-Plugin/actions

2. **Click "Deploy Product Access Manager to Kinsta"** workflow

3. **Click "Run workflow"** button (top right)

4. **Select options**:
   - Branch: `main`
   - Environment: `production`

5. **Click "Run workflow"** (green button)

6. **Watch the deployment**:
   - Click on the workflow run
   - Expand steps to see progress
   - Should see "✅ Deployment to production complete!"

---

## Current Workflow Behavior

### Automatic (No Action Needed)
```
dev branch push → Auto-deploy to STAGING ✅
```

### Manual (You Choose)
```
Actions tab → Run workflow → Choose "staging" or "production" → Deploy
```

---

## Deployment Workflow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                     GitHub Actions Workflow                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Trigger 1: Push to 'dev' branch                                │
│  ─────────────────────────────                                  │
│  Automatically deploys to STAGING                               │
│  Uses: KINSTA_* secrets                                         │
│                                                                  │
│  Trigger 2: Manual "Run workflow"                               │
│  ──────────────────────────────────                             │
│  User chooses: staging OR production                            │
│                                                                  │
│  If "staging" → Uses KINSTA_* secrets                           │
│  If "production" → Uses KINSTAPROD_* secrets                    │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Optional: Auto-Deploy to Production

If you want pushing to `main` branch to auto-deploy to production, update `.github/workflows/deploy.yml`:

### Current (Manual Production Only):
```yaml
on:
  push:
    branches:
      - dev   # Auto-deploy to staging
  workflow_dispatch:
    # Manual deployment to staging or production
```

### Updated (Auto-Deploy Production):
```yaml
on:
  push:
    branches:
      - dev        # Auto-deploy to staging
      - main       # Auto-deploy to production
  workflow_dispatch:
    # Manual deployment to staging or production
```

Then update the logic:
```yaml
- name: Determine environment
  id: env
  run: |
    if [ "${{ github.event_name }}" = "push" ]; then
      if [ "${{ github.ref }}" = "refs/heads/main" ]; then
        echo "env=production" >> $GITHUB_OUTPUT
      else
        echo "env=staging" >> $GITHUB_OUTPUT
      fi
    else
      echo "env=${{ github.event.inputs.environment }}" >> $GITHUB_OUTPUT
    fi
```

⚠️ **Recommendation**: Keep manual deployment for production for now. This gives you control and prevents accidental deployments.

---

## Deployment Checklist

### Before First Production Deployment

- [ ] Get production SSH credentials from Kinsta
- [ ] Add all `KINSTAPROD_*` secrets to GitHub
- [ ] Verify `PLUGIN_FOLDER_NAME` secret is set to `product-access-manager`
- [ ] Test manual deployment to staging first
- [ ] Backup current production plugin (just in case)

### For Each Production Deployment

- [ ] Code is tested on staging
- [ ] All tests pass
- [ ] Documentation is updated
- [ ] Version number is incremented
- [ ] Changes are merged to `main` branch
- [ ] Run manual workflow from GitHub Actions
- [ ] Select `production` environment
- [ ] Monitor deployment logs
- [ ] Verify on live site (holisticpeople.com)
- [ ] Clear production cache: `wp cache flush`
- [ ] Test on live site

---

## Getting Production SSH Credentials from Kinsta

### Method 1: Kinsta Dashboard

1. Log in to **MyKinsta** dashboard
2. Select your **production site** (holisticpeople.com)
3. Click **"Info"** in the left sidebar
4. Under "SFTP/SSH", note:
   - **Host**: Your server IP or hostname
   - **Port**: SSH port number
   - **Username**: Your SSH username
   - **Password**: (if using password authentication)

5. For SSH key:
   - Click your profile (top right)
   - Go to "User Settings"
   - Click "SSH keys" tab
   - Either use existing key or "Add SSH key"

### Method 2: From Your Local Machine

If you already have SSH access locally:

```bash
# Test your connection
ssh -p PORT USERNAME@HOST "echo 'Connection successful!'"

# Find your SSH key
cat ~/.ssh/id_rsa  # or id_ed25519, etc.

# Get the full plugins path
ssh -p PORT USERNAME@HOST "pwd && cd public/wp-content/plugins && pwd"
```

### Method 3: Contact Kinsta Support

If you don't have the credentials:
1. Open a support ticket in MyKinsta
2. Request SSH access details for your production site
3. They'll provide all necessary connection info

---

## Troubleshooting

### Issue: "Permission denied (publickey)"

**Cause**: SSH key not configured correctly

**Solution**:
1. Verify `KINSTAPROD_SSH_KEY` secret contains the **complete** private key
2. Include the header: `-----BEGIN OPENSSH PRIVATE KEY-----`
3. Include the footer: `-----END OPENSSH PRIVATE KEY-----`
4. No extra spaces or line breaks at the beginning or end

### Issue: "No such file or directory"

**Cause**: Wrong path in `KINSTAPROD_PLUGINS_BASE`

**Solution**:
1. SSH into production server
2. Run: `cd public/wp-content/plugins && pwd`
3. Copy the exact output
4. Update `KINSTAPROD_PLUGINS_BASE` secret with that path

### Issue: Workflow succeeds but files not updated

**Cause**: Deploying to wrong server or path

**Solution**:
1. Check workflow logs for "Target path"
2. Verify it matches your production path
3. SSH to production and verify files were uploaded
4. Check file timestamps: `ls -la /path/to/plugins/product-access-manager/`

### Issue: Site still shows old version

**Cause**: Cache not cleared after deployment

**Solution**:
```bash
# SSH to production
ssh -p PORT USERNAME@HOST

# Clear WordPress cache
cd public
wp cache flush

# Verify plugin version
wp plugin list | grep product-access-manager
```

---

## Security Best Practices

### SSH Keys
- ✅ Use separate SSH keys for staging and production
- ✅ Store keys securely in GitHub Secrets (encrypted)
- ✅ Never commit SSH keys to repository
- ✅ Rotate keys periodically

### Deployment
- ✅ Test on staging first
- ✅ Use manual deployment for production (don't auto-deploy main)
- ✅ Review changes before deploying
- ✅ Monitor logs during deployment
- ✅ Have rollback plan ready

### Access Control
- ✅ Limit who can trigger production deployments
- ✅ Use GitHub's environment protection rules (optional)
- ✅ Enable 2FA on GitHub account
- ✅ Audit deployment history regularly

---

## Quick Reference

### Deploy to Staging (Automatic)
```bash
git add .
git commit -m "Your changes"
git push origin dev
# ✅ Auto-deploys to staging
```

### Deploy to Production (Manual)
1. Merge to main: `git push origin main`
2. Go to: https://github.com/Amnonman/Product-Access-Plugin/actions
3. Click workflow → "Run workflow"
4. Select: Branch `main`, Environment `production`
5. Click "Run workflow"
6. Monitor deployment
7. Verify on live site

### Check Deployment Status
- **GitHub**: https://github.com/Amnonman/Product-Access-Plugin/actions
- **Staging**: https://env-holisticpeoplecom-hpdevplus.kinsta.cloud/wp-admin/plugins.php
- **Production**: https://holisticpeople.com/wp-admin/plugins.php

### Clear Cache After Deployment
```bash
# Staging
ssh -p 12872 holisticpeoplecom@35.236.219.140 "cd public && wp cache flush"

# Production (after configuring SSH)
ssh -p PROD_PORT PROD_USER@PROD_HOST "cd public && wp cache flush"
```

---

## Next Steps

1. **Get production SSH credentials** from Kinsta
2. **Add GitHub Secrets** for production (`KINSTAPROD_*`)
3. **Test manual deployment** to staging first
4. **Deploy to production** using GitHub Actions
5. **Verify** on live site (holisticpeople.com)
6. **Document** production SSH details securely (not in repo!)

---

## Support

- **Kinsta Support**: https://kinsta.com/help/
- **GitHub Actions Docs**: https://docs.github.com/en/actions
- **Workflow File**: `.github/workflows/deploy.yml`
- **This Guide**: `PRODUCTION-DEPLOYMENT-SETUP.md`

---

**Status**: Workflow configured ✅  
**Needs**: Production SSH credentials and GitHub Secrets  
**Ready**: Staging auto-deployment working  
**Next**: Configure production secrets and test deployment

