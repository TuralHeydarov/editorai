import copy, gzip, importlib.util, io, json, pathlib, struct, tarfile, tempfile, unittest
from unittest import mock
spec=importlib.util.spec_from_file_location('receiver',pathlib.Path(__file__).parents[1]/'receive.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
SHA='a'*40
class ReleaseSecurityTest(unittest.TestCase):
 def gate(self,app='editorai'):
  keys=('auth','acl','memory','final_data','owner_media','target_deploy')
  return {'schema':'tural_app_release_v1','app':app,'sha':SHA,'expires_at':'2030-01-01T00:30:00Z','checks':dict.fromkeys(keys,'GO'),'evidence':dict.fromkeys(keys,'reviewed-receipt'), 'image_digests':dict.fromkeys(m.APPS[app][0],'b'*64),'supabase_network':'existing-supabase'}
 def test_common_no_go_each_gate_and_memory_floor_are_mandatory(self):
  now=1893456000;slot={'app':'editorai','sha':SHA,'go':True,'expires_at':'2030-01-01T00:30:00Z'};available=m.FLOOR+m.APPS['editorai'][2]
  self.assertEqual(m.validate_gate(self.gate(),slot,'editorai',SHA,available,now)['sha'],SHA)
  for key in self.gate()['checks']:
   gate=self.gate();gate['checks'][key]='NO-GO'
   with self.assertRaises(m.Refused):m.validate_gate(gate,slot,'editorai',SHA,available,now)
  with self.assertRaises(m.Refused):m.validate_gate(self.gate(),slot,'editorai',SHA,available-1,now)
 def test_wrong_app_sha_slot_expiry_and_missing_evidence_refuse(self):
  now=1893456000;slot={'app':'editorai','sha':SHA,'go':True,'expires_at':'2030-01-01T00:30:00Z'}
  for key,value in [('app','almotion'),('sha','c'*40),('expires_at','2020-01-01T00:00:00Z'),('evidence',{})]:
   gate=self.gate();gate[key]=value
   with self.assertRaises(m.Refused):m.validate_gate(gate,slot,'editorai',SHA,99*1024**3,now)
  slot['app']='almotion'
  with self.assertRaises(m.Refused):m.validate_gate(self.gate(),slot,'editorai',SHA,99*1024**3,now)
 def header(self,parts=None,app='editorai'):
  value={'app':app,'sha':SHA,'parts':parts or [{'component':c,'bytes':20,'sha256':'b'*64} for c in m.APPS['editorai'][0]]}
  data=json.dumps(value).encode();return io.BytesIO(struct.pack('!I',len(data))+data)
 def test_cross_app_duplicate_components_oversized_and_truncated_transfer_refuse(self):
  self.assertEqual(m.read_header(self.header(),'editorai')['sha'],SHA)
  with self.assertRaises(m.Refused):m.read_header(self.header(app='almotion'),'editorai')
  with self.assertRaises(m.Refused):m.read_header(self.header([{'component':'backend','bytes':20,'sha256':'b'*64}]*2),'editorai')
  with self.assertRaises(m.Refused):m.read_header(self.header([{'component':c,'bytes':m.MAX_PART+1,'sha256':'b'*64} for c in ('backend','frontend')]),'editorai')
  with self.assertRaises(m.Refused):m.read_exact(io.BytesIO(b'x'),2)
  with self.assertRaises(m.Refused):m.strict_json(b'{"sha":1,"sha":2}')
 def image(self,path,tag,user='www-data',revision=SHA):
  with tarfile.open(path,'w:gz') as t:
   for name,data in {'manifest.json':[{'Config':'config.json','RepoTags':[tag],'Layers':[]}],'config.json':{'config':{'User':user,'Labels':{'org.opencontainers.image.revision':revision}}}}.items():
    value=json.dumps(data).encode();info=tarfile.TarInfo(name);info.size=len(value);t.addfile(info,io.BytesIO(value))
 def test_archive_wrong_tag_revision_or_root_backend_is_rejected_before_docker(self):
  with tempfile.TemporaryDirectory() as d:
   path=pathlib.Path(d)/'image.tar.gz';tag='turalheydarov/editorai-backend:'+SHA
   self.image(path,tag);m.validate_image(path,'editorai','backend',SHA)
   for wrong,user,revision in [('other-app:latest','www-data',SHA),(tag,'root',SHA),(tag,'www-data','c'*40)]:
    self.image(path,wrong,user,revision)
    with self.assertRaises(m.Refused):m.validate_image(path,'editorai','backend',SHA)
 def test_missing_unsafe_provisioned_admission_denies_before_any_docker_or_grant(self):
  with mock.patch.object(m.os,'geteuid',return_value=0), mock.patch.object(m,'private_file',side_effect=m.Refused('missing_provision')), mock.patch.object(m,'run') as run:
   ack=mock.Mock()
   with self.assertRaises(m.Refused):m.receive('editorai',self.header(),ack)
   run.assert_not_called();ack.assert_not_called()

 def test_admission_revoked_during_transfer_refuses_before_any_image_load_or_start(self):
  with tempfile.TemporaryDirectory() as d:
   root=pathlib.Path(d);config=root/'config';package=root/'package';storage=root/'storage'
   for p in (config,package,storage):p.mkdir()
   (root/'editorai').mkdir()
   (root/'editorai/storage/app').mkdir(parents=True)
   compose=package/'netcup.compose.yml';compose.write_text('fixture-only')
   (config/'runtime.env').write_text('fixture-only')
   gate=self.gate();gate['compose_sha256']=__import__('hashlib').sha256(compose.read_bytes()).hexdigest()
   (config/'admission.json').write_text(json.dumps(gate));(root/'slot.json').write_text('{}')
   parts=[];blobs=[]
   for c in ('backend','frontend'):
    image=root/(c+'.gz');self.image(image,'turalheydarov/editorai-'+c+':'+SHA)
    blob=image.read_bytes();blobs.append(blob);digest=__import__('hashlib').sha256(blob).hexdigest()
    parts.append({'component':c,'bytes':len(blob),'sha256':digest});gate['image_digests'][c]=digest
   stream=io.BytesIO(self.header(parts).getvalue()+b''.join(blobs));ack=mock.Mock()
   original=pathlib.Path
   def mapped(value):
    value=str(value)
    for prefix,target in [('/etc/tural-app-release',root),('/etc/tural-app-release/editorai',config),('/usr/local/lib/tural-app-release',root),('/usr/local/lib/tural-app-release/editorai',package),('/opt/stacks',root),('/opt/stacks/editorai/storage/app',storage),('/etc/tural-app-release/global-slot.json',root/'slot.json'),('/etc/tural-app-release/release.lock',root/'release.lock')]:
     if value==prefix:return original(target)
    return original(value)
   original_open=m.os.open
   with mock.patch.object(m.os,'open',side_effect=lambda path,flags,mode=0o777,**kw: original_open(root/'release.lock' if str(path)=='/etc/tural-app-release/release.lock' else path,flags,mode,**kw)), mock.patch.object(m.os,'geteuid',return_value=0), mock.patch.object(m.pathlib,'Path',side_effect=mapped), mock.patch.object(m,'private_file',side_effect=lambda p: (package/original(p).name) if original(p).name=='netcup.compose.yml' else (root/'slot.json' if original(p).name=='global-slot.json' else config/original(p).name)), mock.patch.object(m,'available_memory',return_value=99*1024**3), mock.patch.object(m,'validate_gate',side_effect=[gate,m.Refused('admission_revoked')]), mock.patch.object(m,'run',return_value='') as run, mock.patch.object(m,'validate_image'):
    with self.assertRaisesRegex(m.Refused,'admission_revoked'):m.receive('editorai',stream,ack)
   ack.assert_called_once_with({'status':'admitted','app':'editorai','sha':SHA})
   self.assertEqual(len(run.call_args_list),1);self.assertIn('ps',run.call_args.args[0])
