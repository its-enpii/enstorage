import { pageTitle } from '@/lib/serverMetadata';
import GoogleAccountsClient from './GoogleAccountsClient';

export const metadata = pageTitle('accounts.title');

export default function Page() {
  return <GoogleAccountsClient />;
}