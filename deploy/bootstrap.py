#!/usr/bin/env python3
"""One-time documented control-plane provisioning; never starts apps or admits runtime."""
import argparse, hashlib, json, os, pathlib, pwd, re, shutil, subprocess, sys

def fail(message): raise RuntimeError(message)
def main():
 p=argparse.ArgumentParser();p.add_argument('--app',choices=('almotion','editorai'),required=True);p.add_argument('--revision',required=True);p.add_argument('--public-key',type=pathlib.Path,required=True);p.add_argument('--check',action='store_true');a=p.parse_args()
 if os.geteuid()!=0 or not re.fullmatch('[0-9a-f]{40}',a.revision):fail('Authorized root and exact reviewed main SHA required')
 package=pathlib.Path(__file__).resolve().parent
 expected=json.loads((package/'bootstrap-manifest.json').read_text())
 if expected['revision']!=a.revision or expected['app']!=a.app:fail('Package scope/revision mismatch')
 if set(expected['files'])!=set(('receive.py','netcup.compose.yml','admission.json.example','bootstrap.py')):fail('Incomplete pinned package')
 for name,digest in expected['files'].items():
  if name not in ('receive.py','netcup.compose.yml','admission.json.example','bootstrap.py'):fail('Unexpected package file')
  if hashlib.sha256((package/name).read_bytes()).hexdigest()!=digest:fail('Package digest mismatch')
 public=a.public_key.read_text().strip().split()
 if len(public)<2 or public[0]!='ssh-ed25519' or not re.fullmatch('[A-Za-z0-9+/=]+',public[1]):fail('Only the new app-scoped public key is accepted')
 key=' '.join(public[:2]);user=a.app+'_release';target=pathlib.Path('/usr/local/lib/tural-app-release')/a.app;config=pathlib.Path('/etc/tural-app-release')/a.app;home=pathlib.Path('/var/lib/tural-app-release')/a.app
 sudoers=pathlib.Path('/etc/sudoers.d')/('tural-app-'+a.app+'-release')
 for path in (target,config,home,sudoers):
  if path.exists():fail('App provisioning path already exists; review existing binding rather than overwrite')
 try:pwd.getpwnam(user);fail('Existing account is not this reviewed fresh binding')
 except KeyError:pass
 for parent in (target.parent,config.parent,home.parent,pathlib.Path('/etc/sudoers.d')):
  if parent.exists():
   stat=parent.lstat()
   if parent.is_symlink() or stat.st_uid!=0 or stat.st_mode & 0o022:fail('Unsafe shared parent')
 if a.check:
  print(json.dumps({'status':'reviewable','app':a.app,'revision':a.revision,'user':user,'app_runtime_started':False,'brain_grants_changed':False}));return
 for parent in (target.parent,config.parent,home.parent):parent.mkdir(parents=True,exist_ok=True);os.chmod(parent,0o700 if parent==config.parent else 0o755)
 for path,mode in ((target,0o755),(config,0o700),(home,0o755)):path.mkdir();os.chmod(path,mode)
 for name,mode in (('receive.py',0o500),('netcup.compose.yml',0o400)):
  shutil.copyfile(package/name,target/name);os.chmod(target/name,mode)
 shutil.copyfile(package/'admission.json.example',config/'admission.json');os.chmod(config/'admission.json',0o600)
 # Exact fixed executable/arguments only; no Docker group, general shell sudo or SETENV.
 rule=f'Defaults:{user} env_reset,always_set_home\n{user} ALL=(root) NOPASSWD:NOSETENV: /usr/bin/python3 {target}/receive.py --app {a.app}\n'
 staged=config/'sudoers.next';staged.write_text(rule);os.chmod(staged,0o600)
 subprocess.run(['/usr/sbin/visudo','-cf',str(staged)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 subprocess.run(['/usr/sbin/useradd','--system','--user-group','--password','*','--home-dir',str(home),'--no-create-home','--shell','/bin/sh',user],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 ssh=home/'.ssh';ssh.mkdir();os.chmod(ssh,0o755)
 authorized=ssh/'authorized_keys';authorized.write_text(f'restrict,command="sudo -n /usr/bin/python3 {target}/receive.py --app {a.app}" {key}\n');os.chmod(authorized,0o644)
 os.rename(staged,sudoers);os.chmod(sudoers,0o440)
 receipt={'app':a.app,'revision':a.revision,'key_sha256':hashlib.sha256(key.encode()).hexdigest(),'user':user,'runtime_started':False,'admission':'NO-GO','global_slot_created':False,'brain_grants_changed':False}
 (config/'bootstrap-receipt.json').write_text(json.dumps(receipt,indent=2));os.chmod(config/'bootstrap-receipt.json',0o600)
 print(json.dumps(receipt))
if __name__=='__main__':
 try:main()
 except Exception:print(json.dumps({'status':'refused_or_incomplete','reason':'provisioning_check_failed; preserve paths and review receipt before retry'}));sys.exit(1)
