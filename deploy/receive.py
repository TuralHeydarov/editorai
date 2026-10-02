#!/usr/bin/env python3
"""Root-owned forced-command receiver; no key/grant provisioning or DB/ingress operations."""
import argparse, contextlib, datetime, fcntl, gzip, hashlib, json, os, pathlib, re, shutil, struct, subprocess, sys, tarfile, tempfile
APPS={'almotion':(('backend','frontend','sidecar'),('web','sidecar'),3136*1024**2), 'editorai':(('backend','frontend'),('web',),768*1024**2)}
MAX_PART=1024**3
FLOOR=3*1024**3
class Refused(Exception): pass

def strict_json(raw):
    def pairs(values):
        result={}
        for key,value in values:
            if key in result: raise Refused('duplicate_json_key')
            result[key]=value
        return result
    try: return json.loads(raw,object_pairs_hook=pairs)
    except (ValueError,UnicodeError): raise Refused('invalid_json') from None

def private_file(path):
    path=pathlib.Path(path)
    for item in [path,*path.parents]:
        stat=item.lstat()
        if item.is_symlink() or stat.st_uid!=0 or stat.st_mode & 0o022: raise Refused('unsafe_provisioned_path')
    if not path.is_file() or path.stat().st_mode & 0o077: raise Refused('unsafe_provisioned_file')
    return path

def until(value):
    try: date=datetime.datetime.fromisoformat(value.replace('Z','+00:00'))
    except (ValueError,AttributeError): raise Refused('invalid_expiry') from None
    if date.tzinfo is None: raise Refused('invalid_expiry')
    return date.timestamp()

def validate_gate(gate,slot,app,sha,available,now):
    if app not in APPS or not re.fullmatch('[0-9a-f]{40}',sha): raise Refused('invalid_release')
    if gate.get('schema')!='tural_app_release_v1' or gate.get('app')!=app or gate.get('sha')!=sha: raise Refused('unadmitted_release')
    if not now < until(gate.get('expires_at')) <= now+3600: raise Refused('admission_expired_or_overbroad')
    checks=gate.get('checks',{})
    if any(checks.get(k)!='GO' for k in ('auth','acl','memory','final_data','owner_media','target_deploy')): raise Refused('common_gate_not_go')
    if slot.get('app')!=app or slot.get('sha')!=sha or slot.get('go') is not True or not now < until(slot.get('expires_at')) <= now+3600: raise Refused('no_exclusive_slot')
    if not isinstance(gate.get('evidence'),dict) or any(not isinstance(gate['evidence'].get(k),str) or not gate['evidence'][k].strip() for k in checks): raise Refused('missing_gate_evidence')
    expected=APPS[app][0];digests=gate.get('image_digests',{})
    if set(digests)!=set(expected) or any(not re.fullmatch('[0-9a-f]{64}',v) for v in digests.values()): raise Refused('invalid_image_admission')
    if available < FLOOR+APPS[app][2]: raise Refused('memory_floor_insufficient')
    network=gate.get('supabase_network','')
    if not re.fullmatch('[A-Za-z0-9][A-Za-z0-9_.-]{0,127}',network): raise Refused('invalid_network')
    return gate

def read_exact(stream,count):
    chunks=[]
    while count:
        part=stream.read(min(count,1024*1024))
        if not part: raise Refused('truncated_transfer')
        chunks.append(part);count-=len(part)
    return b''.join(chunks)

def read_header(stream,app):
    length=struct.unpack('!I',read_exact(stream,4))[0]
    if not 1<=length<=16384: raise Refused('oversized_header')
    header=strict_json(read_exact(stream,length))
    if not isinstance(header,dict) or set(header)!={'app','sha','parts'} or header['app']!=app or not isinstance(header['parts'],list): raise Refused('invalid_header')
    if not isinstance(header['sha'],str) or not re.fullmatch('[0-9a-f]{40}',header['sha']): raise Refused('invalid_sha')
    parts=header['parts'];expected=APPS[app][0]
    if len(parts)!=len(expected) or any(not isinstance(p,dict) or set(p)!={'component','bytes','sha256'} for p in parts): raise Refused('invalid_parts')
    if [p['component'] for p in parts]!=list(expected): raise Refused('unexpected_components')
    if any(type(p['bytes']) is not int or not 1<=p['bytes']<=MAX_PART or not isinstance(p['sha256'],str) or not re.fullmatch('[0-9a-f]{64}',p['sha256']) for p in parts): raise Refused('invalid_part')
    return header

def validate_image(path,app,component,sha):
    manifest=None;configs={};total=0;members=0
    with tarfile.open(path,mode='r|gz') as archive:
        for member in archive:
            members+=1;total+=member.size
            if members>256 or total>8*1024**3 or member.name.startswith('/') or '..' in pathlib.PurePosixPath(member.name).parts or not (member.isfile() or member.isdir()): raise Refused('unsafe_image_archive')
            if member.isfile() and (member.name=='manifest.json' or member.size<1024*1024):
                data=archive.extractfile(member).read()
                if member.name=='manifest.json': manifest=strict_json(data)
                elif member.name.endswith('.json') or member.name.startswith('blobs/sha256/'):
                    with contextlib.suppress(Refused):
                        value=strict_json(data)
                        if isinstance(value,dict) and isinstance(value.get('config'),dict): configs[member.name]=value
    tag=f'turalheydarov/{app}-{component}:{sha}'
    if not isinstance(manifest,list) or len(manifest)!=1 or not isinstance(manifest[0],dict) or manifest[0].get('RepoTags')!=[tag]: raise Refused('unexpected_image_tag')
    config=configs.get(manifest[0].get('Config'),{}).get('config',{})
    if config.get('Labels',{}).get('org.opencontainers.image.revision')!=sha: raise Refused('image_revision_mismatch')
    if component=='backend' and config.get('User')!='www-data': raise Refused('backend_must_be_unprivileged')

def available_memory():
    fields=dict(line.split(':',1) for line in pathlib.Path('/proc/meminfo').read_text().splitlines())
    return int(fields['MemAvailable'].split()[0])*1024

def run(command,env,output=False):
    result=subprocess.run(command,env=env,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=180)
    if result.returncode: raise Refused('runtime_command_failed')
    return result.stdout.decode() if output else None

def receive(app,stream,ack=None):
    if os.geteuid()!=0: raise Refused('provisioned_receiver_required')
    configdir=pathlib.Path('/etc/tural-app-release')/app
    package=pathlib.Path('/usr/local/lib/tural-app-release')/app
    header=read_header(stream,app);sha=header['sha']
    def gate():
        value=strict_json(private_file(configdir/'admission.json').read_bytes())
        slot=strict_json(private_file('/etc/tural-app-release/global-slot.json').read_bytes())
        return validate_gate(value,slot,app,sha,available_memory(),datetime.datetime.now(datetime.timezone.utc).timestamp())
    admission=gate();compose=private_file(package/'netcup.compose.yml');runtime=private_file(configdir/'runtime.env')
    if hashlib.sha256(compose.read_bytes()).hexdigest()!=admission.get('compose_sha256'): raise Refused('compose_not_admitted')
    sidecar=private_file(configdir/'sidecar.env') if app=='almotion' else None
    storage=pathlib.Path('/opt/stacks')/app/'storage/app'
    if not storage.is_dir() or storage.is_symlink(): raise Refused('restored_storage_missing')
    for part in header['parts']:
        if part['sha256']!=admission['image_digests'][part['component']]: raise Refused('unadmitted_image')
    env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LANG':'C.UTF-8','RELEASE_SHA':sha,'RUNTIME_ENV_FILE':str(runtime),'STORAGE_PATH':str(storage),'SUPABASE_DOCKER_NETWORK':admission['supabase_network']}
    if sidecar: env['SIDECAR_ENV_FILE']=str(sidecar)
    command=['/usr/bin/docker','compose','--project-name',app+'-netcup','--file',str(compose)]
    for profile in APPS[app][1]: command+=['--profile',profile]
    with os.fdopen(os.open('/etc/tural-app-release/release.lock',os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600),'r+') as lock:
        try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError: raise Refused('another_release_in_progress') from None
        state=configdir/'deployed-sha'
        previous=private_file(state).read_text().strip() if state.exists() else None
        if previous and not re.fullmatch('[0-9a-f]{40}',previous): raise Refused('invalid_previous_release')
        if not previous and run(command+['ps','--quiet'],env,True).strip(): raise Refused('unknown_existing_app_state')
        if shutil.disk_usage(configdir).free < sum(p['bytes'] for p in header['parts'])*2+1024**3: raise Refused('insufficient_staging_disk')
        # Client sends no image bytes before this explicit admission acknowledgement.
        if ack is not None: ack({'status':'admitted','app':app,'sha':sha})
        with tempfile.TemporaryDirectory(prefix=app+'-release-',dir=configdir) as staging:
            paths=[]
            for part in header['parts']:
                path=pathlib.Path(staging)/(part['component']+'.tar.gz');digest=hashlib.sha256();remaining=part['bytes']
                with path.open('xb') as output:
                    os.chmod(path,0o600)
                    while remaining:
                        block=read_exact(stream,min(remaining,1024*1024));output.write(block);digest.update(block);remaining-=len(block)
                if digest.hexdigest()!=part['sha256']: raise Refused('transfer_digest_mismatch')
                validate_image(path,app,part['component'],sha);paths.append(path)
            if stream.read(1): raise Refused('unexpected_transfer_suffix')
            if previous==sha:
                if len(run(command+['ps','--status','running','--quiet'],env,True).splitlines())!=len(APPS[app][0]): raise Refused('existing_release_not_running')
                return {'status':'already_deployed_running','app':app,'sha':sha,'ingress_changed':False}
            for path in paths:
                gate()  # Admission can expire or be revoked during transfer. Recheck before each load.
                run(['/usr/bin/docker','image','load','--input',str(path)],env)
            gate();run(command+['config','--quiet'],env)
            try:
                run(command+['up','--detach','--no-build','--pull','never','--wait','--wait-timeout','60'],env)
                # Laravel/SPA smoke only; no OAuth, media mutation or provider calls.
                ports=(8206,3206) if app=='almotion' else (8207,2994)
                import urllib.request
                for port in ports:
                    url=f'http://127.0.0.1:{port}'+('/up' if port==ports[0] else '/')
                    if urllib.request.urlopen(url,timeout=5).status!=200: raise Refused('health_failed')
            except Exception:
                rollback={**env,'RELEASE_SHA':previous} if previous else env
                run(command+(['up','--detach','--no-build','--pull','never'] if previous else ['down']),rollback)
                raise Refused('release_failed_own_app_rollback_attempted') from None
            temporary=configdir/'deployed-sha.next';temporary.write_text(sha+'\n');os.chmod(temporary,0o600);os.replace(temporary,state)
            return {'status':'deployed_loopback_only','app':app,'sha':sha,'ingress_changed':False,'migrations_run':False}

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--app',choices=APPS,required=True);args=parser.parse_args()
    try: print(json.dumps(receive(args.app,sys.stdin.buffer,ack=lambda value: print(json.dumps(value),flush=True))))
    except Exception as error:
        print(json.dumps({'status':'refused','app':args.app,'reason':str(error) if isinstance(error,Refused) else 'provision_or_runtime_unavailable'}));return 1
    return 0
if __name__=='__main__':sys.exit(main())
