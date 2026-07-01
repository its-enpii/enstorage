import { pageTitle } from '@/lib/serverMetadata';
import ApiKeysClient from './ApiKeysClient';

export const metadata = pageTitle('apikeys.title');

export default function Page() {
  return <ApiKeysClient />;
}