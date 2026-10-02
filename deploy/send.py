#!/usr/bin/env python3
"""CI sender: validate successful main push run and stream exact images to fixed scoped SSH receiver."""
import hashlib,json,os,pathlib,re,select,struct,subprocess,sys,tempfile
app=os.environ['RELEASE_APP'];sha=os.environ['RELEASE_SHA'];run=os.environ['RELEASE_RUN_ID']
if app not in ('almotion','editorai') or not re.fullmatch('[0-9a-f]{40}',sha) or not run.isdigit():sys.exit('Invalid release reference')
repo='TuralHeydarov/'+app
metadata=json.loads(subprocess.check_output(['gh','api',f'repos/{repo}/actions/runs/{run}']))
head=json.loads(subprocess.check_output(['gh','api',f'repos/{repo}/commits/main']))['sha']
if not (metadata['head_sha']==sha==head and metadata['head_branch']=='main' and metadata['event']=='push' and metadata['status']=='completed' and metadata['conclusion']=='success' and metadata['path']=='.github/workflows/schema-isolation.yml' and metadata['head_repository']['full_name']==repo):sys.exit('Release is not the successful current main build')
components=('backend','frontend','sidecar') if app=='almotion' else ('backend','frontend')
with tempfile.TemporaryDirectory() as directory:
 root=pathlib.Path(directory);parts=[];paths=[]
 for component in components:
  target=root/component;target.mkdir()
  subprocess.run(['gh','run','download',run,'--repo',repo,'--name',f'{app}-{component}-{sha}','--dir',str(target)],check=True)
  path=target/'image.tar.gz';digest=hashlib.file_digest(path.open('rb'),'sha256').hexdigest()
  recorded=(target/'image.sha256').read_text().split()
  if recorded!=[digest,'image.tar.gz'] or not 1<=path.stat().st_size<=1024**3:sys.exit('Image digest/size differs')
  parts.append({'component':component,'bytes':path.stat().st_size,'sha256':digest});paths.append(path)
 key=root/'key';key.write_text(os.environ['TARGET_SSH_PRIVATE_KEY']);key.chmod(0o600)
 known=root/'known_hosts';known.write_text(os.environ['TARGET_KNOWN_HOSTS']);known.chmod(0o600)
 user=app+'_release';host='159.195.83.193'
 process=subprocess.Popen(['ssh','-T','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','ConnectTimeout=10','-o','IdentitiesOnly=yes','-o','UserKnownHostsFile='+str(known),'-i',str(key),user+'@'+host],stdin=subprocess.PIPE,stdout=subprocess.PIPE)
 try:
  header=json.dumps({'app':app,'sha':sha,'parts':parts},separators=(',',':')).encode();process.stdin.write(struct.pack('!I',len(header))+header);process.stdin.flush()
  if not select.select([process.stdout],[],[],35)[0]:raise RuntimeError('No target admission acknowledgement')
  line=process.stdout.readline(16385)
  acknowledgement=json.loads(line)
  if acknowledgement!={'status':'admitted','app':app,'sha':sha}:raise RuntimeError('Target admission denied before any image upload')
  for path in paths:
   with path.open('rb') as source:
    while block:=source.read(1024*1024):process.stdin.write(block)
  process.stdin.close();output=process.stdout.read(16385);code=process.wait(timeout=600)
  if len(output)>16384:raise RuntimeError('Invalid target receipt')
  receipt=json.loads(output)
  if code==0 and receipt.get('status') not in ('deployed_loopback_only','already_deployed_running'):raise RuntimeError('Unconfirmed target result')
  print(json.dumps(receipt))
 except Exception:
  process.kill();process.wait();raise
 if code:sys.exit('Scoped target receiver refused or release failed')
