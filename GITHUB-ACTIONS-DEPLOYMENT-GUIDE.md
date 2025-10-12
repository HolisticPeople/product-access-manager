# GitHub Actions Deployment Guide

**Quick visual guide for deploying via GitHub Actions**

---

## 🚀 How to Deploy to Production

### Step 1: Go to GitHub Actions

Visit: https://github.com/Amnonman/Product-Access-Plugin/actions

Or from your repository:
1. Click the **"Actions"** tab (top menu)

### Step 2: Select the Workflow

You'll see: **"Deploy Product Access Manager to Kinsta"**

Click on it.

### Step 3: Click "Run workflow"

On the right side, you'll see a **"Run workflow"** button (dropdown).

Click it.

### Step 4: Configure Deployment

A form will appear with options:

```
┌─────────────────────────────────────────┐
│ Run workflow                            │
├─────────────────────────────────────────┤
│                                         │
│ Branch: [main ▼]                        │
│                                         │
│ Deployment environment                  │
│ [production ▼]                          │
│   - staging                             │
│   - production                          │
│                                         │
│        [Run workflow]                   │
│                                         │
└─────────────────────────────────────────┘
```

**Select**:
- **Branch**: `main` (for production)
- **Environment**: `production`

### Step 5: Run It!

Click the green **"Run workflow"** button.

### Step 6: Monitor Progress

1. The page will refresh
2. You'll see a new workflow run at the top (yellow dot = running)
3. Click on the workflow run to see details
4. Watch the steps execute in real-time

### Step 7: Verify Success

When done, you'll see:
- ✅ Green checkmark = Success
- ❌ Red X = Failed (check logs)

**Success message**: "✅ Deployment to production complete!"

---

## 🎯 Current Deployment Flow

```
┌──────────────┐
│  Local Dev   │
└──────┬───────┘
       │ git push origin dev
       ▼
┌──────────────┐
│ Dev Branch   │──────────► Auto-deploys to STAGING
└──────┬───────┘            (No action needed)
       │ PR/Merge
       ▼
┌──────────────┐
│ Main Branch  │
└──────┬───────┘
       │
       ▼
┌──────────────────────┐
│ GitHub Actions       │
│ (Manual Trigger)     │──► Select "production"
└──────┬───────────────┘
       │
       ▼
┌──────────────┐
│ PRODUCTION   │──────────► holisticpeople.com
└──────────────┘
```

---

## ⚙️ Deployment Options

### Option 1: Deploy to Staging (Manual)

**When**: Testing changes before production

**Steps**:
1. Actions → Run workflow
2. Branch: `dev`
3. Environment: `staging`
4. Run workflow

**Result**: Deploys to staging server

### Option 2: Deploy to Production (Manual)

**When**: Releasing tested code to live site

**Steps**:
1. Actions → Run workflow
2. Branch: `main`
3. Environment: `production`
4. Run workflow

**Result**: Deploys to holisticpeople.com

⚠️ **Note**: Requires production secrets to be configured first!

---

## 📋 Pre-Deployment Checklist

Before deploying to production:

- [ ] Code tested on staging
- [ ] All features working
- [ ] Documentation updated
- [ ] Version number incremented
- [ ] Changes merged to `main`
- [ ] Backup current production (optional)

---

## 🔍 Monitoring a Deployment

### Viewing Logs

When workflow is running:

1. **Click on the workflow run** (the yellow/green dot)
2. **Click on "deploy"** job (left sidebar)
3. **Expand each step** to see details:
   - ✅ Checkout code
   - ✅ Determine environment
   - ✅ Set environment variables
   - ✅ Setup SSH key
   - ✅ Deploy to production
   - ✅ Cleanup

### What to Look For

**Good Signs**:
```
Deploying to: production
Target path: /www/holisticpeople_123/public/wp-content/plugins/product-access-manager
sending incremental file list
product-access-manager.php
✅ Deployment to production complete!
```

**Warning Signs**:
```
Permission denied (publickey)
No such file or directory
rsync: connection unexpectedly closed
```

If you see warnings, check the [Troubleshooting](#troubleshooting) section.

---

## 🔧 Troubleshooting

### Workflow Not Running

**Issue**: "Run workflow" button is grayed out

**Solution**: 
- Make sure you're on the workflow page (not the main Actions page)
- Check you have push access to the repository

### Permission Denied

**Issue**: `Permission denied (publickey)`

**Solution**:
1. Check that `KINSTAPROD_SSH_KEY` secret is set
2. Verify the SSH key is correct (complete private key)
3. Test SSH connection manually

### Files Not Deploying

**Issue**: Workflow succeeds but files aren't updated

**Solution**:
1. Check the "Target path" in logs matches production path
2. Verify `KINSTAPROD_PLUGINS_BASE` secret is correct
3. SSH to server and check file timestamps
4. Clear cache: `wp cache flush`

### Wrong Environment

**Issue**: Deployed to staging instead of production

**Solution**:
- Double-check you selected "production" in the dropdown
- Verify you're deploying from `main` branch (not `dev`)
- Check workflow logs to see which environment was used

---

## 📊 Deployment History

### View Past Deployments

1. Go to Actions tab
2. Click on workflow name
3. See list of all runs:
   - ✅ Green = Success
   - ❌ Red = Failed
   - 🟡 Yellow = Running
   - ⚪ Gray = Canceled

### Re-run a Deployment

If a deployment fails:
1. Click on the failed workflow run
2. Click "Re-run all jobs" (top right)
3. Or fix issues and run a new deployment

---

## 🛡️ Safety Features

### What Gets Deployed

✅ **Included**:
- PHP files (`*.php`)
- JavaScript files (`*.js`)
- Documentation files (`*.md`)
- CSS files (if any)

❌ **Excluded** (see `.github/deploy-exclude.txt`):
- `.git/` directory
- `.github/` directory
- `node_modules/`
- `Plans and reports/`
- Log files
- Development files

### Rollback

If something goes wrong:

**Option 1**: Deploy previous version
1. Go to Actions
2. Find last successful deployment
3. Re-run that workflow

**Option 2**: Manual rollback via SSH
```bash
ssh -p PORT USER@HOST
cd /path/to/plugins/product-access-manager
# Restore from backup
```

**Option 3**: Use GitHub commit history
1. Checkout previous commit: `git checkout COMMIT_HASH`
2. Deploy that version via Actions

---

## 🎓 Best Practices

### 1. Test on Staging First

Always deploy to staging before production:
```
Local → Dev Branch → Staging → Main Branch → Production
```

### 2. Use Descriptive Commit Messages

Good:
```
git commit -m "v2.15.0 - Fixed FiboSearch filtering on search results page"
```

Bad:
```
git commit -m "fixes"
```

### 3. Monitor Deployments

- Don't close the browser during deployment
- Watch the logs for any errors
- Verify on the live site after deployment

### 4. Clear Cache After Deployment

```bash
# Always clear cache after production deployment
wp cache flush
```

### 5. Keep Secrets Secure

- Never commit SSH keys to repository
- Use GitHub Secrets for credentials
- Rotate keys periodically

---

## 📞 Need Help?

### GitHub Actions Issues
- Check workflow logs for error messages
- Review `PRODUCTION-DEPLOYMENT-SETUP.md` for configuration
- Check GitHub Actions status: https://www.githubstatus.com/

### Server Issues
- Contact Kinsta support: https://kinsta.com/help/
- Verify SSH access manually
- Check server logs

### Plugin Issues
- Check WordPress debug log
- Verify plugin version in WordPress admin
- Test with debug mode enabled temporarily

---

## 🚦 Current Status

### Staging Deployment
- **Status**: ✅ Configured and working
- **Trigger**: Automatic on push to `dev`
- **Environment**: https://env-holisticpeoplecom-hpdevplus.kinsta.cloud
- **Version**: v2.15.0

### Production Deployment
- **Status**: ⚠️ Needs configuration
- **Trigger**: Manual via Actions (after secrets configured)
- **Environment**: https://holisticpeople.com
- **Version**: v1.9.0 (needs update)

---

## ✅ Quick Start

**To deploy v2.15.0 to production right now:**

1. ✅ Code is ready (v2.15.0 in `main` branch)
2. ⚠️ Configure production secrets (see `PRODUCTION-DEPLOYMENT-SETUP.md`)
3. Go to: https://github.com/Amnonman/Product-Access-Plugin/actions
4. Click "Deploy Product Access Manager to Kinsta"
5. Click "Run workflow"
6. Select: Branch `main`, Environment `production`
7. Click "Run workflow"
8. Monitor progress
9. Verify on holisticpeople.com
10. Clear cache: `wp cache flush`

---

**Ready to deploy?** Follow the steps above! 🚀

