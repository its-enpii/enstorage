import { pageTitle } from '@/lib/serverMetadata';
import ProfileClient from './ProfileClient';

export const metadata = pageTitle('profile.title');

export default function Page() {
  return <ProfileClient />;
}