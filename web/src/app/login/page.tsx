import { pageTitle } from '@/lib/serverMetadata';
import LoginClient from './LoginClient';

export const metadata = pageTitle('auth.login.title');

export default function Page() {
  return <LoginClient />;
}